<?php

namespace App\Services;

use Carbon\Carbon;

/**
 * Отчёт по смене: кто, сколько сделал и сколько брака — по вкладкам текущего участка.
 * Запись с несколькими исполнителями засчитывается каждому из них.
 * Брак — по тому же правилу, что и в статистике (StatsService::defectSql).
 */
class ShiftReport
{
    private const WORKERS = ['worker1', 'worker2', 'worker3', 'worker4', 'worker5'];

    public function __construct(private SectionContext $sections, private StatsService $stats) {}

    /** @return array{0: Carbon, 1: Carbon} начало и конец смены */
    public static function bounds(Carbon $date, int $shift): array
    {
        $cfg = config("shifts.list.$shift") ?? config('shifts.list.1');
        $start = $date->copy()->setTimeFromTimeString($cfg['start']);
        $end = $date->copy()->setTimeFromTimeString($cfg['end']);
        if ($end->lte($start)) $end->addDay();

        return [$start, $end];
    }

    public function build(Carbon $from, Carbon $to): array
    {
        $workers = [];   // ключ исполнителя => [total, items, defect, sheets => [slug => [total, defect]]]
        $sheets = [];    // slug => [title, total, items, defect]
        $names = new \App\Support\WorkerNames();   // разные написания одного ФИО — один исполнитель

        foreach ($this->sections->sheets() as $slug => $cfg) {
            if (empty($cfg['model'])) continue;
            $fields = $cfg['fields'] ?? [];
            $wf = array_values(array_filter(self::WORKERS, fn ($f) => isset($fields[$f])));
            $hasQty = isset($fields['qty']);
            $defect = $this->stats->defectSql($cfg)['defect'];

            $select = array_merge(['id'], $wf, $hasQty ? ['qty'] : []);
            $query = $cfg['model']::query()->select($select)->whereBetween('created_at', [$from, $to]);
            if ($defect) {
                $query->selectRaw("CASE WHEN {$defect['sql']} THEN 1 ELSE 0 END AS is_defect", $defect['bindings']);
            }

            $rows = $query->get();
            if ($rows->isEmpty()) continue;

            $s = ['title' => $cfg['title'], 'total' => 0, 'items' => 0, 'defect' => 0];
            foreach ($rows as $row) {
                $items = $hasQty ? max(1, (int) $row->qty) : 1;
                $isDefect = (int) ($row->is_defect ?? 0);
                $s['total']++;
                $s['items'] += $items;
                $s['defect'] += $isDefect;

                $ids = [];
                foreach ($wf as $f) {
                    foreach (\App\Support\WorkerNames::split((string) $row->{$f}) as $raw) {
                        if ($id = $names->id($raw)) $ids[$id] = true;
                    }
                }
                foreach (array_keys($ids) as $name) {
                    $w = &$workers[$name];
                    $w ??= ['total' => 0, 'items' => 0, 'defect' => 0, 'sheets' => []];
                    $w['total']++;
                    $w['items'] += $items;
                    $w['defect'] += $isDefect;
                    $w['sheets'][$slug] ??= ['total' => 0, 'defect' => 0];
                    $w['sheets'][$slug]['total']++;
                    $w['sheets'][$slug]['defect'] += $isDefect;
                    unset($w);
                }
            }
            $sheets[$slug] = $s;
        }

        uasort($workers, fn ($a, $b) => $b['items'] <=> $a['items']);
        $labelled = [];
        foreach ($workers as $id => $w) {
            $labelled[$names->label($id)] = $w + ['known' => $names->known($id), 'variants' => $names->variants($id)];
        }
        $workers = $labelled;

        return [
            'workers' => $workers,
            'sheets'  => $sheets,
            'totals'  => [
                'total'  => array_sum(array_column($sheets, 'total')),
                'items'  => array_sum(array_column($sheets, 'items')),
                'defect' => array_sum(array_column($sheets, 'defect')),
            ],
        ];
    }
}
