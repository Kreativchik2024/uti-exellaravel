<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PgpRecord;
use App\Models\ResearchRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * История борта по этапам: проверки на ПГП → исследование по браку → смены статуса (в работе, в разбор,
 * в конвейер) → комментарии → пометки ОТК. Борт — все записи ПГП с тем же номером борта.
 * Смены статуса берутся из журнала действий (там есть время и кто менял).
 */
class BoardHistory
{
    /**
     * @return array{board: ?string, stage: array{label: string, tone: string},
     *               events: list<array{at: ?Carbon, title: string, text: ?string, tone: string, who: ?string, url: ?string}>}
     */
    public function for(PgpRecord $pgp): array
    {
        $board = trim((string) $pgp->board_number);
        $pgps = $board !== ''
            ? PgpRecord::query()->where('board_number', $board)->orderBy('created_at')->orderBy('id')->get()
            : collect([$pgp]);

        $researches = ResearchRecord::query()->whereIn('pgp_record_id', $pgps->pluck('id'))
            ->with('comments.user')->orderBy('id')->get();
        $researchIds = $researches->pluck('id')->all();

        $statusLogs = $researchIds
            ? AuditLog::query()->where('area', 'research')->whereIn('action', ['status', 'repair', 'act'])->where('subject_type', 'record')
                ->whereIn('subject_id', $researchIds)->with('user')->orderBy('id')->get()
            : collect();
        $techLogs = AuditLog::query()->where('area', 'pgp')->where('action', 'tech')->where('subject_type', 'record')
            ->whereIn('subject_id', $pgps->pluck('id'))->with('user')->orderBy('id')->get();

        $events = [];

        foreach ($pgps as $p) {
            $passed = $p->status === 'Сдан';
            $events[] = [
                'at'    => $p->created_at,
                'title' => 'Проверка на ПГП: ' . ($p->status ?: 'без статуса') . ($p->record_date ? ' (' . $p->record_date->format('d.m.Y') . ')' : ''),
                'text'  => $passed ? null : (trim((string) $p->defect_reason) ?: null),
                'tone'  => $passed ? 'good' : 'bad',
                'who'   => $p->worker1,
                'url'   => route('records.show', ['pgp', $p->id]),
            ];
        }

        foreach ($researches as $r) {
            $details = array_filter([
                $r->category ? 'Категория: ' . $r->category : null,
            ]);
            $events[] = [
                'at'    => $r->created_at,
                'title' => "Исследование №{$r->id} заведено",
                'text'  => $details ? implode(' · ', $details) : null,
                'tone'  => 'bad',
                'who'   => null,
                'url'   => route('records.show', ['research', $r->id]),
            ];
            foreach ($r->comments as $c) {
                $events[] = [
                    'at'    => $c->created_at,
                    'title' => "Комментарий к исследованию №{$r->id}",
                    'text'  => $c->body,
                    'tone'  => 'neutral',
                    'who'   => $c->user?->name,
                    'url'   => route('records.show', ['research', $r->id]) . '#comments',
                ];
            }
        }

        foreach ($statusLogs as $log) {
            $who = $log->user?->name ?? $log->user_name;
            $url = route('records.show', ['research', $log->subject_id]);
            // «Исследование #12: Замена — МОТОРАМА · Кросс 3» / «… акт на замену оформлен (…)»
            $after = trim((string) preg_replace('/^Исследование #\d+:\s*/u', '', (string) $log->summary));

            if ($log->action === 'repair') {
                $isReplacement = str_starts_with($after, 'Замена');
                $events[] = ['at' => $log->created_at, 'title' => "Исследование №{$log->subject_id}: " . ($isReplacement ? 'замена' : 'ремонт'),
                    'text' => $after, 'tone' => $isReplacement ? 'warn' : 'neutral', 'who' => $who, 'url' => $url];
                continue;
            }
            if ($log->action === 'act') {
                $events[] = ['at' => $log->created_at, 'title' => "Исследование №{$log->subject_id}: акт на замену оформлен",
                    'text' => null, 'tone' => 'good', 'who' => $who, 'url' => $url];
                continue;
            }

            // «Исследование #12: Не обработан → В работе» — новый этап после стрелки
            $to = trim((string) (preg_split('/→/u', (string) $log->summary)[1] ?? ''));
            $events[] = [
                'at'    => $log->created_at,
                'title' => "Исследование №{$log->subject_id}: " . ($to ?: 'статус изменён'),
                'text'  => null,
                'tone'  => $this->toneOfLabel($to),
                'who'   => $who,
                'url'   => $url,
            ];
        }

        foreach ($techLogs as $log) {
            $events[] = [
                'at'    => $log->created_at,
                'title' => 'Пометка ОТК',
                'text'  => trim((string) preg_replace('/^.*?:\s*/u', '', (string) $log->summary)) ?: null,
                'tone'  => 'neutral',
                'who'   => $log->user?->name ?? $log->user_name,
                'url'   => route('records.show', ['pgp', $log->subject_id]),
            ];
        }

        // По времени; в одну и ту же секунду — проверка ПГП, затем заведение исследования, затем остальное
        $rank = fn ($e) => str_starts_with($e['title'], 'Проверка на ПГП') ? 0 : (str_ends_with($e['title'], 'заведено') ? 1 : 2);
        usort($events, fn ($a, $b) => [$a['at']?->getTimestamp() ?? 0, $rank($a)] <=> [$b['at']?->getTimestamp() ?? 0, $rank($b)]);

        return ['board' => $board !== '' ? $board : null, 'stage' => $this->stage($pgps, $researches), 'events' => $events];
    }

    /** Где борт сейчас: по последней проверке на ПГП и исследованию по ней. */
    private function stage(Collection $pgps, Collection $researches): array
    {
        $last = $pgps->last();
        if (! $last) return ['label' => 'Нет данных', 'tone' => 'neutral'];
        if ($last->status === 'Сдан') return ['label' => 'Сдан на ПГП', 'tone' => 'good'];

        $research = $researches->where('pgp_record_id', $last->id)->last();
        if (! $research) return ['label' => 'Брак на ПГП, исследование не заведено', 'tone' => 'bad'];

        $s = ResearchRecord::STATUSES[$research->repair_status ?: 'new'] ?? ResearchRecord::STATUSES['new'];

        return ['label' => "Исследование №{$research->id}: {$s['label']}", 'tone' => $s['tone']];
    }

    private function toneOfLabel(string $label): string
    {
        foreach (ResearchRecord::STATUSES as $s) {
            if ($s['label'] === $label) return $s['tone'];
        }

        return 'neutral';
    }
}
