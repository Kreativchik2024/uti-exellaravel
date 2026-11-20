<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\PgpRecord;
use App\Models\ResearchRecord;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Наведение порядка в необработанных бортах (одноразово, по старым данным). Правила по порядку:
 *  1. «Борт снова был на ПГП» — по фюзеляжу после брака есть следующая проверка ПГП: ремонт, значит, закончен →
 *     исследование «Отправлен на испытания», следующая проверка связывается с ним как повторная (after_research_id).
 *     Новых исследований команда не заводит: запись «Не сдан» без исследования остаётся как есть.
 *  2. «По результату» — «Не обработан», но «Результат» заполнен (свободный текст, ищем ключевые слова):
 *     «разбор» → «Отправлен в разбор»; «в работе» → «В работе»; испытания / сборка / настройка / отремонтирован / исправен… → на испытания.
 *  2б. «Загружено из таблицы» — без автора и не из ПГП (старые данные), результат пустой или не распознан → «Отправлен в разбор» с пометкой.
 *  3. «Давно висит» — всё ещё «Не обработан» дольше --months месяцев → «Отправлен в разбор» с пометкой.
 * Без --apply только показывает отчёт. Изменения идут через модели — попадают в журнал действий.
 */
class CleanupBoards extends Command
{
    protected $signature = 'boards:cleanup
        {--apply : Внести изменения (без него — только отчёт)}
        {--months=3 : Через сколько месяцев «Не обработан» уходит в разбор}
        {--force : Не спрашивать подтверждение}';

    protected $description = 'Навести порядок в необработанных бортах: закрыть отремонтированные, связать проверки, разобрать давние';

    /** Ключевые слова в «Результате» → статус; проверяются по порядку. */
    private const RESULT_KEYWORDS = [
        'analysis' => ['разбор'],
        'in_work'  => ['в работе', 'в ремонте', 'ожидает'],
        'conveyor' => ['испытани', 'сборк', 'настройк', 'отремонтир', 'устранен', 'исправ', 'подтвержд', 'передан', 'отдан', 'отправлен', 'заменен', 'замена'],
    ];

    /** Статус по тексту «Результата» или null — не распознан. */
    public static function statusByResult(?string $result): ?string
    {
        $text = mb_strtolower(trim((string) $result));
        if ($text === '') return null;
        foreach (self::RESULT_KEYWORDS as $status => $words) {
            foreach ($words as $w) if (str_contains($text, $w)) return $status;
        }

        return null;
    }

    /** @var array<int, array> план: [rule, research?, pgp?, fuselage, from, to, link?, note?] */
    private array $plan = [];

    /** id проверок ПГП, уже занятых как повторные (существующие и запланированные). */
    private array $usedChecks = [];

    public function handle(): int
    {
        $this->plan = [];
        $months = max(1, (int) $this->option('months'));
        $checks = $this->checksByFuselage();
        $this->usedChecks = PgpRecord::whereNotNull('after_research_id')->pluck('id')->flip()->map(fn () => true)->all();

        $research = $this->boardResearch();
        $this->planReturned($research, $checks);
        $this->planByResult();
        $this->planImported();
        $this->planStale($months);

        if (! $this->plan) {
            $this->info('Необработанных бортов, которые можно разобрать по правилам, нет.');
            return self::SUCCESS;
        }

        $this->report($months);
        if (! $this->option('apply')) {
            $this->line('Это только отчёт. Применить: php artisan boards:cleanup --apply' . ($months !== 3 ? " --months={$months}" : ''));
            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('Применить изменения?', false)) {
            $this->warn('Отменено.');
            return self::SUCCESS;
        }

        $done = DB::transaction(fn () => $this->apply());
        AuditLog::write('research', 'cleanup', "Чистка базы бортов: изменено {$done}", ['section_id' => null]);
        $this->info("Готово, изменено: {$done}");

        return self::SUCCESS;
    }

    private static function norm(?string $v): string
    {
        return mb_strtoupper(trim((string) $v));
    }

    /** Проверки ПГП по фюзеляжу в порядке времени. @return Collection<string, Collection<PgpRecord>> */
    private function checksByFuselage(): Collection
    {
        return PgpRecord::query()->whereNotNull('fuselage_number')->where('fuselage_number', '!=', '')
            ->orderBy('record_date')->orderBy('id')
            ->get(['id', 'fuselage_number', 'record_date', 'status', 'after_research_id', 'created_at'])
            ->groupBy(fn ($p) => self::norm($p->fuselage_number));
    }

    /** Исследования бортов: по браку ПГП, категория «борта» или без категории. */
    private function boardResearch(): Collection
    {
        $boardTitle = config('sheets.map.pgp.title');

        return ResearchRecord::query()->with('pgpRecord:id,fuselage_number,record_date,status,created_at')->withExists('recheck')
            ->where(fn ($q) => $q->whereNotNull('pgp_record_id')->orWhere('category', $boardTitle)
                ->orWhereNull('category')->orWhere('category', ''))
            ->orderBy('id')->get();
    }

    /** Следующая после $after проверка того же фюзеляжа, ещё не занятая как повторная. */
    private function nextCheck(Collection $list, callable $isAfter): ?PgpRecord
    {
        return $list->first(fn ($p) => $isAfter($p) && ! isset($this->usedChecks[$p->id]));
    }

    private static function laterThan(?Carbon $date, ?Carbon $created, ?int $id = null): callable
    {
        return function (PgpRecord $p) use ($date, $created, $id) {
            if ($id !== null && $p->id === $id) return false;
            if (! $date || ! $p->record_date) return $id !== null ? $p->id > $id : false;
            if ($p->record_date->gt($date)) return true;
            if ($p->record_date->lt($date)) return false;

            return $id !== null ? $p->id > $id : ($created && $p->created_at && $p->created_at->gt($created));
        };
    }

    /** Правило 1: борт после брака снова был на ПГП (или его запись теперь «Сдан»). */
    private function planReturned(Collection $research, Collection $checks): void
    {
        foreach ($research as $r) {
            $status = $r->repair_status ?: 'new';
            $fuselage = $r->pgpRecord?->fuselage_number ?: $r->fuselage_no;
            if (self::norm($fuselage) === '') continue;

            $list = $checks[self::norm($fuselage)] ?? collect();
            $next = $r->recheck_exists ? null : $this->nextCheck($list, $r->pgpRecord
                ? self::laterThan($r->pgpRecord->record_date, $r->pgpRecord->created_at, $r->pgpRecord->id)
                : self::laterThan($r->record_date, $r->created_at));
            $ownDelivered = $r->pgpRecord?->status === 'Сдан';

            if ($status === 'conveyor' && ! $next) continue;              // уже закрыт и связывать нечего
            if ($status !== 'conveyor' && ! $next && ! $ownDelivered) continue;

            if ($next) $this->usedChecks[$next->id] = true;
            $this->plan[] = ['rule' => $status === 'conveyor' ? 'Связать повторную проверку' : 'Борт снова был на ПГП',
                'research' => $r, 'pgp' => $r->pgpRecord, 'fuselage' => $fuselage, 'from' => $status, 'to' => 'conveyor', 'link' => $next];
        }
    }

    private function planned(): array
    {
        return collect($this->plan)->pluck('research')->pluck('id')->flip()->all();
    }

    /** Правило 2: «Не обработан», но результат уже записан. */
    private function planByResult(): void
    {
        $skip = $this->planned();
        ResearchRecord::query()->where('repair_status', 'new')->whereNotNull('result')->orderBy('id')->get()
            ->each(function ($r) use ($skip) {
                $to = self::statusByResult($r->result);
                if (! $to || isset($skip[$r->id])) return;
                $this->plan[] = ['rule' => 'По результату -> ' . mb_strtolower(ResearchRecord::STATUSES[$to]['label']), 'research' => $r, 'pgp' => null,
                    'fuselage' => $r->fuselage_no ?: $r->module_serial, 'from' => 'new', 'to' => $to, 'link' => null];
            });
    }

    /** Правило 2б: загружено из таблицы (без автора, не из ПГП), результат пустой или не распознан. */
    private function planImported(): void
    {
        $skip = $this->planned();
        ResearchRecord::query()->where('repair_status', 'new')->whereNull('created_by')->whereNull('pgp_record_id')->orderBy('id')->get()
            ->each(function ($r) use ($skip) {
                if (isset($skip[$r->id])) return;
                $empty = trim((string) $r->result) === '';
                $this->plan[] = ['rule' => 'Загружено из таблицы, результат ' . ($empty ? 'пустой' : 'не распознан'), 'research' => $r, 'pgp' => null,
                    'fuselage' => $r->fuselage_no ?: $r->module_serial, 'from' => 'new', 'to' => 'analysis', 'link' => null,
                    'note' => 'Отправлено в разбор при чистке базы ' . now()->format('d.m.Y') . ' (загружено из таблицы, результат ' . ($empty ? 'не указан' : 'не распознан') . ')'];
            });
    }

    /** Правило 3: давно «Не обработан». */
    private function planStale(int $months): void
    {
        $skip = $this->planned();
        $edge = now()->subMonths($months)->startOfDay();
        ResearchRecord::query()->where('repair_status', 'new')
            ->where(fn ($q) => $q->where('record_date', '<', $edge)->orWhere(fn ($w) => $w->whereNull('record_date')->where('created_at', '<', $edge)))
            ->orderBy('id')->get()
            ->each(function ($r) use ($skip, $months) {
                if (isset($skip[$r->id])) return;
                $this->plan[] = ['rule' => "Не обработан дольше {$months} мес.", 'research' => $r, 'pgp' => null,
                    'fuselage' => $r->fuselage_no ?: $r->module_serial, 'from' => 'new', 'to' => 'analysis', 'link' => null,
                    'note' => 'Отправлено в разбор при чистке базы ' . now()->format('d.m.Y') . " (не обработано дольше {$months} мес.)"];
            });
    }

    private function report(int $months): void
    {
        $label = fn ($s) => ResearchRecord::STATUSES[$s]['label'] ?? $s;

        $this->info('Будет изменено: ' . count($this->plan));
        $this->table(['Правило', 'Количество'], collect($this->plan)->countBy('rule')->map(fn ($n, $k) => [$k, $n])->values()->all());

        $this->table(['Правило', 'Исследование', 'ПГП', 'Фюзеляж', 'Статус', 'Повторная проверка'],
            collect($this->plan)->take(40)->map(fn ($p) => [
                $p['rule'],
                '№' . $p['research']->id,
                $p['pgp'] ? '№' . $p['pgp']->id : '—',
                $p['fuselage'] ?: '—',
                $p['from'] === $p['to'] ? 'без изменений' : $label($p['from']) . ' -> ' . $label($p['to']),
                $p['link'] ? "№{$p['link']->id} ({$p['link']->status}, " . $p['link']->record_date?->format('d.m.Y') . ')' : '—',
            ])->all());
        if (count($this->plan) > 40) $this->line('… и ещё ' . (count($this->plan) - 40));

        // Как разобран текст «Результата» — проверить, что ключевые слова сработали верно
        $byText = collect($this->plan)->filter(fn ($p) => trim((string) $p['research']->result) !== '' && $p['from'] !== $p['to'])
            ->groupBy(fn ($p) => mb_strimwidth(trim($p['research']->result), 0, 60, '…') . '|' . $p['to'])
            ->map(fn ($g, $k) => [explode('|', $k)[0], $label(explode('|', $k)[1]), $g->count()])
            ->sortByDesc(fn ($row) => $row[2])->values();
        if ($byText->isNotEmpty()) {
            $this->line('Результат -> статус (' . $byText->count() . ' разных текстов' . ($byText->count() > 60 ? ', первые 60' : '') . '):');
            $this->table(['Результат', 'Статус', 'Записей'], $byText->take(60)->all());
        }
    }

    private function apply(): int
    {
        $done = 0;
        foreach ($this->plan as $p) {
            $r = ResearchRecord::find($p['research']->id);

            $r->timestamps = false;                                    // дату изменения записи не трогаем
            $r->repair_status = $p['to'];
            if (! empty($p['note'])) $r->analysis_actions = trim(trim((string) $r->analysis_actions) . "\n[" . $p['note'] . ']');
            $r->save();

            if ($p['link'] && ! $r->recheck()->exists()) {
                $check = PgpRecord::find($p['link']->id);
                if ($check && ! $check->after_research_id) {
                    $check->timestamps = false;
                    $check->after_research_id = $r->id;
                    $check->save();
                }
            }

            AuditLog::write('research', 'cleanup', "Чистка базы: исследование #{$r->id} — {$p['rule']}"
                . ($p['link'] ? ", повторная проверка ПГП #{$p['link']->id}" : ''),
                ['subject_type' => 'record', 'subject_id' => $r->id, 'section_id' => null]);
            $done++;
        }

        return $done;
    }
}
