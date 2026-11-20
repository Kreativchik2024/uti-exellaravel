<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PgpRecord;
use App\Models\ResearchRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Журнал борта по этапам производства:
 *   1. Прошивка и настройка периферии (Кросс 3, прошивка камеры…; модуль связи — нет, он переходит с номером борта)
 *   2. Сборка (кронштейн с камерой…)
 *   3. ПГП — проверка: борт сдан или ушёл в ремонт
 *   4. Ремонт — исследование, ремонт/замена узла, статусы, комментарии; после ремонта — снова ПГП
 *   5. ОТК — акт на замену, приёмка после ремонта, пометки технолога
 *
 * Борт — все записи ПГП с тем же № моторамы или № фюзеляжа (номер борта при ремонте не переходит дальше,
 * поэтому он — только запасной вариант, см. boardKeys). Периферия подтягивается
 * по номерам из записей ПГП (поля со ссылкой 'lookup' в config/sheets.php) и дальше по цепочке
 * (кронштейн → прошивка камеры). У каждого события — кто делал и кто внёс; изменения полей — из журнала действий.
 */
class BoardHistory
{
    public const STAGES = [
        'setup'    => '1. Прошивка и настройка',
        'assembly' => '2. Сборка',
        'pgp'      => '3. ПГП',
        'repair'   => '4. Ремонт',
        'otk'      => '5. Технолог',
    ];

    /** Вкладки, которые относятся к сборке; остальная периферия — прошивка и настройка. */
    private const ASSEMBLY_SHEETS = ['camera_bracket'];

    /**
     * Номера в ПГП, по которым периферия к борту НЕ привязывается: модуль связи, как и номер борта,
     * переходит с наклейкой на другой борт, если прежний неисправен — к физическому борту он не относится.
     */
    private const NOT_BOARD_LOOKUPS = ['manet_number'];

    /**
     * @return array{board: ?string, keys: array<string,string>, stage: array{label: string, tone: string},
     *               stages: array<string, array{label: string, count: int}>,
     *               events: list<array{at: ?Carbon, stage: string, title: string, text: ?string, tone: string, who: ?string, by: ?string, url: ?string}>,
     *               changes: list<array{at: ?Carbon, who: ?string, record: string, action: string, url: ?string, fields: list<array{label: string, old: ?string, new: ?string}>}>}
     */
    public function for(PgpRecord $pgp): array
    {
        $keys = $this->boardKeys($pgp);
        $pgps = $this->boardPgps($pgp, $keys);

        return $this->build($pgp, $keys, $pgps, $pgps);
    }

    /**
     * Что известно о борте по номерам из ещё не сохранённой формы ПГП (моторама, фюзеляж, номера периферии).
     * Номер борта не учитывается: в форме это новая наклейка, по ней нашлись бы чужие борта.
     * @param array<string, mixed> $numbers поля записи ПГП
     */
    public function forNumbers(array $numbers): array
    {
        $draft = new PgpRecord();
        $draft->forceFill(array_intersect_key($numbers, array_flip(['frame_serial', 'fuselage_number', 'cross3_number', 'bracket_serial'])));

        $keys = $this->boardKeys($draft);
        unset($keys['board_number']);
        $pgps = $keys ? $this->boardPgps($draft, $keys) : collect();

        // периферия — и по записям борта, и по номерам, введённым в форме
        return $this->build($draft, $keys, $pgps, $pgps->concat([$draft]));
    }

    /** Есть ли что показать: хоть одно событие. */
    public static function isEmpty(array $journal): bool
    {
        return ! $journal['events'];
    }

    private function build(PgpRecord $pgp, array $keys, Collection $pgps, Collection $peripheralSources): array
    {
        $researches = ResearchRecord::query()
            ->where(function ($q) use ($pgps, $keys) {
                $q->whereIn('pgp_record_id', $pgps->pluck('id'));
                if (isset($keys['frame_serial']))    $q->orWhere('frame_serial', $keys['frame_serial']);
                if (isset($keys['fuselage_number'])) $q->orWhere('fuselage_no', $keys['fuselage_number']);
            })
            ->with(['comments.user', 'creator'])->orderBy('id')->get();

        $peripherals = $this->peripherals($peripheralSources);   // [[slug, cfg, record], …]

        $events = [];
        foreach ($peripherals as [$slug, $cfg, $rec]) $events[] = $this->peripheralEvent($slug, $cfg, $rec);
        foreach ($pgps as $p) array_push($events, ...$this->pgpEvents($p));
        foreach ($researches as $r) array_push($events, ...$this->researchEvents($r));
        array_push($events, ...$this->logEvents($pgps, $researches));

        // По времени; в одну секунду — по этапам (ПГП раньше заведения исследования и т.д.)
        $order = array_flip(array_keys(self::STAGES));
        usort($events, fn ($a, $b) => [$a['at']?->getTimestamp() ?? 0, $order[$a['stage']], $a['rank'] ?? 0]
            <=> [$b['at']?->getTimestamp() ?? 0, $order[$b['stage']], $b['rank'] ?? 0]);

        $stages = [];
        foreach (self::STAGES as $k => $label) {
            $stages[$k] = ['label' => $label, 'count' => count(array_filter($events, fn ($e) => $e['stage'] === $k))];
        }

        return [
            'board'   => filled($pgp->board_number) ? (string) $pgp->board_number : null,
            'keys'    => $keys,
            'stage'   => $pgps->isEmpty() ? ['label' => 'На ПГП ещё не был', 'tone' => 'neutral'] : $this->current($pgps, $researches),
            'stages'  => $stages,
            'events'  => $events,
            'changes' => $this->changes($pgps, $researches, $peripherals),
        ];
    }

    // ================================================================ кто этот борт

    /**
     * Номера, по которым узнаётся физический борт: № моторамы и № фюзеляжа (только «настоящие» — есть цифра, от 4 знаков).
     * Номер борта сюда не входит: при уходе в ремонт он дальше не переходит (наклейка уходит на другой борт),
     * поэтому по нему склеились бы разные борта. Он берётся, только если ни моторамы, ни фюзеляжа в записи нет.
     */
    private function boardKeys(PgpRecord $pgp): array
    {
        $keys = [];
        foreach (['frame_serial', 'fuselage_number'] as $f) {
            $v = trim((string) $pgp->{$f});
            if (mb_strlen($v) >= 4 && preg_match('/\d/u', $v)) $keys[$f] = $v;
        }
        if (! $keys && filled($b = trim((string) $pgp->board_number))) $keys['board_number'] = $b;

        return $keys;
    }

    private function boardPgps(PgpRecord $pgp, array $keys): Collection
    {
        if (! $keys) return collect([$pgp]);

        return PgpRecord::query()->with('creator')
            ->where(function ($q) use ($keys) {
                foreach ($keys as $f => $v) $q->orWhere($f, $v);
            })
            ->orderBy('created_at')->orderBy('id')->get();
    }

    // ================================================================ 1–2. периферия по номерам

    /**
     * Записи периферии по номерам из записей ПГП и дальше по цепочке (до 2 шагов): кронштейн → прошивка камеры.
     * @return list<array{0: string, 1: array, 2: Model}>
     */
    private function peripherals(Collection $pgps): array
    {
        $found = [];
        $seen = [];
        $queue = [];
        $pgpCfg = config('sheets.map.pgp');
        foreach ($pgps as $p) $queue[] = [$pgpCfg, $p, 0];

        while ($queue) {
            [$cfg, $rec, $depth] = array_shift($queue);
            if ($depth >= 2) continue;
            foreach ($cfg['fields'] ?? [] as $name => $f) {
                if (empty($f['lookup'])) continue;
                if ($rec instanceof PgpRecord && in_array($name, self::NOT_BOARD_LOOKUPS, true)) continue;
                $value = trim((string) $rec->{$name});
                $slug = $f['lookup']['sheet'];
                $target = config("sheets.map.$slug");
                if ($value === '' || ! $target || empty($target['model'])) continue;

                foreach ($target['model']::query()->with('creator')->where($f['lookup']['match_by'], $value)->get() as $t) {
                    if (isset($seen[$slug][$t->id])) continue;
                    $seen[$slug][$t->id] = true;
                    $found[] = [$slug, $target, $t];
                    $queue[] = [$target, $t, $depth + 1];
                }
            }
        }

        return $found;
    }

    private function peripheralEvent(string $slug, array $cfg, Model $rec): array
    {
        $serial = $rec->serial_number ?? $rec->camera_serial ?? null;
        $ok = $this->isGood($cfg, $rec);
        $details = array_filter([
            filled($rec->assembled_by ?? null) ? 'Собрал: ' . $rec->assembled_by : null,
            $ok ? null : $this->badText($cfg, $rec),
            filled($rec->fault_code ?? null) ? 'Код неисправности: ' . $rec->fault_code : null,
        ]);

        return [
            'at'    => $rec->created_at,
            'stage' => in_array($slug, self::ASSEMBLY_SHEETS, true) ? 'assembly' : 'setup',
            'title' => ($cfg['title'] ?? $slug) . ($serial ? ' · ' . $serial : '') . ($rec->record_date ? ' (' . Carbon::parse($rec->record_date)->format('d.m.Y') . ')' : ''),
            'text'  => $details ? implode(' · ', $details) : null,
            'tone'  => $ok ? 'good' : 'bad',
            'who'   => $this->workers($rec),
            'by'    => $rec->creator?->name,
            'url'   => route('records.show', [$slug, $rec->id]),
        ];
    }

    // ================================================================ 3. ПГП

    /**
     * Проверки на ПГП по записи. Борт после ремонта сдаётся в той же записи (PgpBoardLookup) — поэтому
     * каждая смена статуса из журнала действий — отдельная проверка: «Не сдан» → ремонт → «Сдан».
     */
    private function pgpEvents(PgpRecord $p): array
    {
        $statusLogs = AuditLog::query()->where('area', 'records')->where('action', 'updated')
            ->where('subject_type', 'pgp')->where('subject_id', $p->id)->with('user')->orderBy('id')->get()
            ->filter(fn ($log) => isset($log->changes['status']));
        if ($statusLogs->isEmpty()) return [$this->pgpEvent($p)];

        $first = $statusLogs->first()->changes['status'][0] ?? null;
        $events = [$this->pgpEvent($p, $first ?: null, null)];
        foreach ($statusLogs as $log) {
            $to = (string) ($log->changes['status'][1] ?? '');
            $events[] = $this->pgpEvent($p, $to, $log);
        }

        return $events;
    }

    /** Одна проверка: $status — статус на тот момент (null — текущий), $log — запись журнала о повторной проверке. */
    private function pgpEvent(PgpRecord $p, ?string $status = null, ?AuditLog $log = null): array
    {
        $status ??= $p->status;
        $passed = $status === 'Сдан';
        $text = array_filter([
            $passed ? null : (trim((string) $p->defect_reason) ?: null),
            filled($p->fault_code) && ! $passed ? 'Код неисправности: ' . $p->fault_code : null,
            filled($p->technologist) && $passed ? 'Представитель ОТК: ' . $p->technologist : null,
        ]);

        if ($log) {
            return [
                'at'    => $log->created_at,
                'stage' => 'pgp',
                'title' => 'Повторная проверка на ПГП: ' . ($status ?: 'без статуса') . ' (' . $log->created_at->format('d.m.Y') . ')',
                'text'  => $text ? implode(' · ', $text) : null,
                'tone'  => $passed ? 'good' : 'bad',
                'who'   => $this->workers($p),
                'by'    => $log->user?->name ?? $log->user_name,
                'url'   => route('records.show', ['pgp', $p->id]),
            ];
        }

        return [
            'at'    => $p->created_at,
            'stage' => 'pgp',
            'title' => 'Проверка на ПГП: ' . ($status ?: 'без статуса') . ($p->record_date ? ' (' . $p->record_date->format('d.m.Y') . ')' : ''),
            'text'  => $text ? implode(' · ', $text) : null,
            'tone'  => $passed ? 'good' : 'bad',
            'who'   => $this->workers($p),
            'by'    => $p->creator?->name,
            'url'   => route('records.show', ['pgp', $p->id]),
        ];
    }

    // ================================================================ 4. ремонт

    private function researchEvents(ResearchRecord $r): array
    {
        $url = route('records.show', ['research', $r->id]);
        $events = [[
            'at'    => $r->created_at,
            'stage' => 'repair',
            'rank'  => 0,
            'title' => "Исследование №{$r->id} заведено",
            'text'  => trim(implode(' · ', array_filter([$r->category ? 'Категория: ' . $r->category : null, $r->reported_defect]))) ?: null,
            'tone'  => 'bad',
            'who'   => null,
            'by'    => $r->creator?->name,
            'url'   => $url,
        ]];
        foreach ($r->comments as $c) {
            $events[] = ['at' => $c->created_at, 'stage' => 'repair', 'rank' => 2, 'title' => "Комментарий к исследованию №{$r->id}",
                'text' => $c->body, 'tone' => 'neutral', 'who' => $c->user?->name, 'by' => null, 'url' => $url . '#comments'];
        }

        return $events;
    }

    /** Статусы, ремонт/замена, акт и приёмка, пометки ОТК — из журнала действий (там время и кто). */
    private function logEvents(Collection $pgps, Collection $researches): array
    {
        $events = [];
        $researchIds = $researches->pluck('id')->all();

        $logs = $researchIds
            ? AuditLog::query()->where('area', 'research')->whereIn('action', ['status', 'repair', 'act'])->where('subject_type', 'record')
                ->whereIn('subject_id', $researchIds)->with('user')->orderBy('id')->get()
            : collect();

        foreach ($logs as $log) {
            $who = $log->user?->name ?? $log->user_name;
            $url = route('records.show', ['research', $log->subject_id]);
            // «Исследование #12: Замена — МОТОРАМА · Кросс 3» / «… акт на замену оформлен (…)»
            $after = trim((string) preg_replace('/^Исследование #\d+:\s*/u', '', (string) $log->summary));

            if ($log->action === 'repair') {
                // «Замена — A; Ремонт — B» — пунктов может быть несколько
                // «Замена — …» (старые) или «Замена (с использованием комплектующих) — …»
                $isReplacement = (bool) preg_match('/(^|; )Замена( \([^)]*\))? — /u', $after);
                $isRepair = (bool) preg_match('/(^|; )Ремонт( \([^)]*\))? — /u', $after);
                $events[] = ['at' => $log->created_at, 'stage' => 'repair', 'rank' => 1,
                    'title' => "Исследование №{$log->subject_id}: " . ($isReplacement && $isRepair ? 'ремонт и замена' : ($isReplacement ? 'замена' : 'ремонт')),
                    'text' => $after, 'tone' => $isReplacement ? 'warn' : 'neutral', 'who' => $who, 'by' => null, 'url' => $url];
            } elseif ($log->action === 'act') {
                $accepted = str_contains($after, 'ремонт принят');
                $events[] = ['at' => $log->created_at, 'stage' => 'otk', 'rank' => 0,
                    'title' => "Исследование №{$log->subject_id}: " . ($accepted ? 'ремонт принят технологом' : 'акт на замену оформлен'),
                    'text' => null, 'tone' => 'good', 'who' => $who, 'by' => null, 'url' => $url];
            } else {
                // «Исследование #12: Не обработан → В работе» — новый этап после стрелки
                $to = trim((string) (preg_split('/→/u', (string) $log->summary)[1] ?? ''));
                $to = ResearchRecord::LEGACY_LABELS[$to] ?? $to;
                $events[] = ['at' => $log->created_at, 'stage' => 'repair', 'rank' => 1,
                    'title' => "Исследование №{$log->subject_id}: " . ($to ?: 'статус изменён'),
                    'text' => null, 'tone' => $this->toneOfLabel($to), 'who' => $who, 'by' => null, 'url' => $url];
            }
        }

        $techLogs = AuditLog::query()->where('area', 'pgp')->where('action', 'tech')->where('subject_type', 'record')
            ->whereIn('subject_id', $pgps->pluck('id'))->with('user')->orderBy('id')->get();
        foreach ($techLogs as $log) {
            $events[] = ['at' => $log->created_at, 'stage' => 'otk', 'rank' => 1, 'title' => 'Пометка технолога',
                'text' => trim((string) preg_replace('/^.*?:\s*/u', '', (string) $log->summary)) ?: null,
                'tone' => 'neutral', 'who' => $log->user?->name ?? $log->user_name, 'by' => null,
                'url' => route('records.show', ['pgp', $log->subject_id])];
        }

        return $events;
    }

    // ================================================================ журнал изменений

    /** Кто, когда и что изменил во всех записях борта (ПГП, исследования, периферия) — «было → стало». */
    private function changes(Collection $pgps, Collection $researches, array $peripherals): array
    {
        $bySlug = ['pgp' => $pgps->pluck('id')->all(), 'research' => $researches->pluck('id')->all()];
        foreach ($peripherals as [$slug, , $rec]) $bySlug[$slug][] = $rec->id;
        $bySlug = array_filter($bySlug);
        if (! $bySlug) return [];

        $logs = AuditLog::query()->where('area', 'records')
            ->where(function ($q) use ($bySlug) {
                foreach ($bySlug as $slug => $ids) $q->orWhere(fn ($w) => $w->where('subject_type', $slug)->whereIn('subject_id', $ids));
            })
            ->with('user')->orderByDesc('id')->limit(200)->get();

        $labels = \App\Http\Controllers\AuditController::fieldLabels();
        $actions = ['created' => 'создана', 'updated' => 'изменена', 'deleted' => 'удалена'];
        $fmt = fn ($v) => $v === null || $v === '' ? null : (is_bool($v) ? ($v ? 'Да' : 'Нет') : (string) $v);

        return $logs->map(function ($log) use ($labels, $actions, $fmt) {
            $cfg = config("sheets.map.{$log->subject_type}", []);
            $fields = [];
            if ($log->action === 'updated') {
                foreach ((array) $log->changes as $field => [$old, $new]) {
                    $fields[] = ['label' => $cfg['fields'][$field]['label'] ?? $labels[$field] ?? $field, 'old' => $fmt($old), 'new' => $fmt($new)];
                }
            }

            return [
                'at'     => $log->created_at,
                'who'    => $log->user?->name ?? $log->user_name,
                'record' => ($cfg['title'] ?? $log->subject_type) . ' №' . $log->subject_id,
                'action' => $actions[$log->action] ?? $log->action,
                'url'    => $log->action === 'deleted' ? null : route('records.show', [$log->subject_type, $log->subject_id]),
                'fields' => $fields,
            ];
        })->all();
    }

    // ================================================================ вспомогательное

    /** Где борт сейчас: по последней проверке на ПГП и исследованию по ней. */
    private function current(Collection $pgps, Collection $researches): array
    {
        $last = $pgps->last();
        if (! $last) return ['label' => 'Нет данных', 'tone' => 'neutral'];
        if ($last->status === 'Сдан') return ['label' => 'Сдан на ПГП', 'tone' => 'good'];

        $research = $researches->where('pgp_record_id', $last->id)->last();
        if (! $research) return ['label' => 'Брак на ПГП, исследование не заведено', 'tone' => 'bad'];

        $s = ResearchRecord::STATUSES[$research->repair_status ?: 'new'] ?? ResearchRecord::STATUSES['new'];

        return ['label' => "Исследование №{$research->id}: {$s['label']}", 'tone' => $s['tone']];
    }

    private function workers(Model $rec): ?string
    {
        $names = array_filter(array_map(fn ($f) => trim((string) ($rec->{$f} ?? '')), ['worker1', 'worker2', 'worker3', 'worker4', 'worker5']));

        return $names ? implode(', ', array_unique($names)) : null;
    }

    private function isGood(array $cfg, Model $rec): bool
    {
        $fields = $cfg['health_fields'] ?? [];
        if ($fields) {
            $good = $cfg['health_good_values'] ?? ['Работает'];
            foreach ($fields as $f) {
                $v = $rec->{$f} ?? null;
                if ($v !== null && $v !== '' && ! in_array($v, $good, true)) return false;
            }

            return true;
        }

        return blank($rec->fault_code ?? null);
    }

    private function badText(array $cfg, Model $rec): ?string
    {
        $good = $cfg['health_good_values'] ?? ['Работает'];
        $bad = [];
        foreach ($cfg['health_fields'] ?? [] as $f) {
            $v = $rec->{$f} ?? null;
            if ($v !== null && $v !== '' && ! in_array($v, $good, true)) $bad[] = ($cfg['fields'][$f]['label'] ?? $f) . ': ' . $v;
        }

        return $bad ? implode(', ', $bad) : null;
    }

    private function toneOfLabel(string $label): string
    {
        foreach (ResearchRecord::STATUSES as $s) {
            if ($s['label'] === $label) return $s['tone'];
        }

        return 'neutral';
    }
}
