<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PgpRecord;
use App\Models\ResearchRecord;
use Illuminate\Database\Eloquent\Builder;

/**
 * Брак ПГП → исследование → ремонт.
 *  - запись ПГП с браком (по общему правилу StatsService::defectSql) сразу заводит исследование (одно на запись);
 *  - ремонтник видит исследования по браку ПГП, пока у них не стоит «Отремонтировано: Да»;
 *  - отремонтированный борт показывается зелёным «Отремонтировано», а не красным браком.
 */
class PgpDefectResearch
{
    public function __construct(private StatsService $stats, private SectionContext $sections) {}

    /** Брак ли запись ПГП — то же правило, что в статистике и отчёте о браке. */
    public function isDefect(PgpRecord $pgp): bool
    {
        $rule = $this->stats->defectSql(config('sheets.map.pgp'))['defect'];

        return $rule !== null && PgpRecord::query()->whereKey($pgp->id)->whereRaw($rule['sql'], $rule['bindings'])->exists();
    }

    /** Завести исследование по браку записи ПГП, если его ещё нет. @return ResearchRecord|null созданное */
    public function syncFor(PgpRecord $pgp): ?ResearchRecord
    {
        if (! $this->isDefect($pgp) || $pgp->researches()->exists()) return null;

        $defect = trim((string) $pgp->defect_reason);
        $board  = trim((string) $pgp->board_number);

        $research = new ResearchRecord();
        $research->forceFill([
            'pgp_record_id'   => $pgp->id,
            'record_date'     => $pgp->record_date ?? now(),
            'category'        => config('sheets.map.pgp.title'),
            'repair_status'   => 'new',
            'worker1'         => $pgp->worker1 ?: '—',
            'worker2'         => $pgp->worker2,
            'worker3'         => $pgp->worker3,
            'worker4'         => $pgp->worker4,
            'worker5'         => $pgp->worker5,
            'fuselage_no'     => $pgp->fuselage_number,
            'frame_serial'    => $pgp->frame_serial,
            'bracket_serial'  => $pgp->bracket_serial,
            'reported_defect' => trim(($board !== '' ? "Борт {$board}. " : '') . ($defect !== '' ? $defect : 'Брак на ПГП')),
            'rejected_by'     => $pgp->worker1,
            'fault_code'      => $pgp->fault_code,
            'is_repaired'     => false,
            'created_by'      => $pgp->created_by,
        ])->save();

        AuditLog::write('research', 'created', "Исследование #{$research->id} заведено по браку ПГП #{$pgp->id}" . ($board !== '' ? " (борт {$board})" : ''),
            ['subject_type' => 'record', 'subject_id' => $research->id, 'section_id' => null]);

        return $research;
    }

    /** Исследования по браку ПГП, ещё не отремонтированные (список ремонтника). null — на участке нет вкладки. */
    public function openForRepair(): ?Builder
    {
        if (! isset($this->sections->sheets()['research'])) return null;

        return ResearchRecord::query()->whereNotNull('pgp_record_id')->where('is_repaired', false);
    }

    public function openForRepairCount(): int
    {
        return $this->openForRepair()?->count() ?? 0;
    }

    /**
     * Ждут технолога (ОТК): замена — нужен акт; ремонт, отправленный в конвейер, — нужна приёмка.
     * Любое исследование — заведённое по браку ПГП и внесённое вручную (см. ResearchRecord::techCheckKind).
     */
    public function actsNeeded(): ?Builder
    {
        if (! isset($this->sections->sheets()['research'])) return null;

        return ResearchRecord::query()->whereNull('act_done_at')
            ->where(fn ($q) => $q->where('repair_action', 'replacement')
                ->orWhere(fn ($w) => $w->where('repair_action', 'repair')->where('repair_status', 'conveyor')));
    }

    public function actsNeededCount(): int
    {
        return $this->actsNeeded()?->count() ?? 0;
    }

    /**
     * Борта, вернувшиеся на ПГП после ремонта: запись ПГП ещё не «Сдан», исследование отправлено в конвейер.
     * Их перепроверяет любой исполнитель и ставит «Сдан» в этой же записи (PgpRecord::returnedFromRepair).
     */
    public function returnedFromRepair(): ?Builder
    {
        $cfg = $this->sections->sheets()['pgp'] ?? null;
        if (! $cfg) return null;

        return $cfg['model']::query()
            ->where(fn ($q) => $q->whereNull('status')->orWhere('status', '!=', 'Сдан'))
            ->whereHas('researches', fn ($r) => $r->where('repair_status', 'conveyor'));
    }

    public function returnedFromRepairCount(): int
    {
        return $this->returnedFromRepair()?->count() ?? 0;
    }

    /** Какие из записей ПГП уже отремонтированы. @return array<int, true> id => true */
    public function repairedPgpIds(array $pgpIds): array
    {
        if (! $pgpIds) return [];

        return ResearchRecord::query()->whereIn('pgp_record_id', $pgpIds)->where('is_repaired', true)
            ->pluck('pgp_record_id')->mapWithKeys(fn ($id) => [(int) $id => true])->all();
    }
}
