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
        if (! $rule) return $query->whereRaw('1 = 0');

        $query->whereRaw($rule['sql'], $rule['bindings']);

        return $this->withoutPassedLater($query);
    }

    /**
     * Убирает брак, после которого борт уже прошёл ПГП: есть более поздняя запись «Сдан»
     * с тем же № моторамы или № фюзеляжа (как в App\Services\BoardHistory — номер борта не учитывается,
     * он при ремонте переходит на другой борт). «Позже» — по дате записи, в один день — по id.
     */
    private function withoutPassedLater(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();
        // «Настоящий» номер: от 4 знаков и есть цифра (переносимо для MySQL и SQLite — без REGEXP)
        $real = fn (string $col) => "p.`$col` IS NOT NULL AND LENGTH(p.`$col`) >= 4 AND ("
            . implode(' OR ', array_map(fn ($d) => "p.`$col` LIKE '%$d%'", range(0, 9))) . ')';

        return $query->whereNotExists(function ($q) use ($table, $real) {
            $q->from("$table as later")->whereColumn('later.id', '!=', 'p.id')
                ->where('later.status', 'Сдан')
                ->where(function ($w) use ($real) {
                    $w->whereRaw("({$real('frame_serial')} AND later.frame_serial = p.frame_serial)")
                      ->orWhereRaw("({$real('fuselage_number')} AND later.fuselage_number = p.fuselage_number)");
                })
                ->where(function ($w) {
                    $w->whereColumn('later.record_date', '>', 'p.record_date')
                      ->orWhere(fn ($s) => $s->whereColumn('later.record_date', 'p.record_date')->whereColumn('later.id', '>', 'p.id'));
                });
        })->from("$table as p")->select('p.*');
    }

    public function openCount(): int
    {
        return $this->openQuery()?->count() ?? 0;
    }
}
