<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;

/**
 * Рекламации ПГП текущего участка: записи, которые по общему правилу брака (StatsService::defectSql)
 * считаются браком, и которые технолог ОТК ещё не отметил как обработанные.
 */
class PgpComplaints
{
    public function __construct(private SectionContext $sections, private StatsService $stats) {}

    /** Вкладка ПГП текущего участка или null, если её нет. */
    public function cfg(): ?array
    {
        return $this->sections->sheets()['pgp'] ?? null;
    }

    public function openQuery(): ?Builder
    {
        $cfg = $this->cfg();
        if (! $cfg) return null;

        $query = $cfg['model']::query()->where('tech_processed', false);
        $rule = $this->stats->defectSql($cfg)['defect'];

        return $rule ? $query->whereRaw($rule['sql'], $rule['bindings']) : $query->whereRaw('1 = 0');
    }

    public function openCount(): int
    {
        return $this->openQuery()?->count() ?? 0;
    }
}
