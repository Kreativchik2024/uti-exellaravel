<?php

namespace App\Exports;

use App\Exports\Support\TableSheet;
use App\Exports\Support\Workbook;

/**
 * Статистика: лист «Сводка» (по вкладкам и по исполнителям) и лист «Исполнители по вкладкам»
 * — плоская таблица с фильтром, чтобы в Excel можно было отобрать нужное.
 */
class StatsExport extends Workbook
{
    public function __construct(string $periodLabel, string $section, array $perSheet, array $totals)
    {
        $info = [['Участок', $section], ['Период', $periodLabel], Workbook::stamp()];

        $bySheet = [];
        $sum = ['total' => 0, 'good' => 0, 'defects' => 0];
        foreach ($perSheet as $d) {
            $has = $d['hasDefects'] ?? true;
            $bySheet[] = [$d['title'], $d['unit'] ?? 'записей', $d['total'],
                $has ? $d['good'] : null, $has ? $d['defects'] : null, $has ? Workbook::share($d['defects'], $d['total']) : null,
                count($d['perUser'])];
            if ($has) {
                $sum['total'] += $d['total'];
                $sum['good'] += $d['good'];
                $sum['defects'] += $d['defects'];
            }
        }

        $people = [];
        foreach ($totals['perUser'] ?? [] as $name => $count) {
            $people[] = [$name, $count, Workbook::share($count, $totals['total'])];
        }

        $flat = [];
        foreach ($perSheet as $d) {
            foreach ($d['perUser'] as $u) {
                $flat[] = [$d['title'], $u['name'], $u['count'], Workbook::share($u['count'], $d['total'])];
            }
        }

        $int = ['type' => 'int'];
        parent::__construct([
            TableSheet::make('Сводка', 'Статистика', $info, [
                [
                    'caption' => 'По вкладкам',
                    'columns' => [
                        ['title' => 'Вкладка'], ['title' => 'Учёт'], ['title' => 'Всего'] + $int,
                        ['title' => 'Исправных'] + $int, ['title' => 'Брак'] + $int,
                        ['title' => 'Брак, %', 'type' => 'pct'], ['title' => 'Исполнителей'] + $int,
                    ],
                    'rows'  => $bySheet,
                    'empty' => 'За период записей нет',
                    // Брак суммируется только по вкладкам, где он считается
                    'total' => $bySheet ? ['Итого (вкладки с учётом брака)', null, $sum['total'], $sum['good'], $sum['defects'], Workbook::share($sum['defects'], $sum['total']), null] : null,
                ],
                [
                    'caption' => 'По исполнителям (все вкладки)',
                    'columns' => [['title' => 'Исполнитель'], ['title' => 'Записей'] + $int, ['title' => 'Доля', 'type' => 'pct']],
                    'rows'  => $people,
                    'empty' => 'Нет данных',
                    'total' => $people ? ['Всего записей', $totals['total'], null] : null,
                ],
            ]),
            TableSheet::make('Исполнители по вкладкам', null, [], [[
                'columns' => [['title' => 'Вкладка'], ['title' => 'Исполнитель'], ['title' => 'Записей'] + $int, ['title' => 'Доля во вкладке', 'type' => 'pct']],
                'rows'  => $flat,
                'empty' => 'Нет данных',
            ]]),
        ]);
    }
}
