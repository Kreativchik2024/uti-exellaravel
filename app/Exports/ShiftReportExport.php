<?php

namespace App\Exports;

use App\Exports\Support\TableSheet;
use App\Exports\Support\Workbook;
use Carbon\Carbon;

/** Отчёт по смене: лист «Смена» (исполнители, вкладки) и лист «Подробно» — исполнитель × вкладка. */
class ShiftReportExport extends Workbook
{
    public function __construct(array $data, Carbon $from, Carbon $to, string $section)
    {
        $info = [['Участок', $section ?: '—'], ['Начало смены', $from->format('d.m.Y H:i')], ['Конец смены', $to->format('d.m.Y H:i')], Workbook::stamp()];
        $t = $data['totals'];
        $int = ['type' => 'int'];
        $pct = ['title' => 'Брак, %', 'type' => 'pct'];

        $workers = [];
        $flat = [];
        foreach ($data['workers'] as $name => $w) {
            $workers[] = [$name, $w['total'], $w['items'], $w['defect'], Workbook::share($w['defect'], $w['total'])];
            foreach ($w['sheets'] as $slug => $s) {
                $flat[] = [$name, $data['sheets'][$slug]['title'] ?? $slug, $s['total'], $s['defect'], Workbook::share($s['defect'], $s['total'])];
            }
        }
        $sheets = array_map(fn ($s) => [$s['title'], $s['total'], $s['items'], $s['defect'], Workbook::share($s['defect'], $s['total'])], array_values($data['sheets']));
        $total = ['Итого', $t['total'], $t['items'], $t['defect'], Workbook::share($t['defect'], $t['total'])];

        parent::__construct([
            TableSheet::make('Смена', 'Отчёт по смене', $info, [
                [
                    'caption' => 'По исполнителям',
                    'columns' => [['title' => 'Исполнитель'], ['title' => 'Записей'] + $int, ['title' => 'Изделий'] + $int, ['title' => 'Брак'] + $int, $pct],
                    'rows' => $workers, 'empty' => 'За смену записей нет',
                ],
                [
                    'caption' => 'По вкладкам',
                    'columns' => [['title' => 'Вкладка'], ['title' => 'Записей'] + $int, ['title' => 'Изделий'] + $int, ['title' => 'Брак'] + $int, $pct],
                    'rows' => $sheets, 'empty' => 'За смену записей нет', 'total' => $sheets ? $total : null,
                ],
            ]),
            TableSheet::make('Подробно', null, [], [[
                'columns' => [['title' => 'Исполнитель'], ['title' => 'Вкладка'], ['title' => 'Записей'] + $int, ['title' => 'Брак'] + $int, $pct],
                'rows' => $flat, 'empty' => 'Нет данных',
            ]]),
        ]);
    }

    public static function pct(int $part, int $total): string
    {
        return $total ? round($part / $total * 100, 1) . '%' : '—';
    }
}
