<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StatsExport implements FromArray, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param string $periodLabel подпись периода, как на странице статистики («За всё время», «С … по …»)
     * @param string $section     название участка
     */
    public function __construct(
        protected string $periodLabel,
        protected string $section,
        protected array $perSheet,
        protected array $totals,
    ) {}

    public function title(): string
    {
        return 'Статистика';   // имя листа Excel: до 31 символа, без «:», «/» и т.п.
    }

    public function headings(): array
    {
        return [
            'Вкладка',
            'Всего',
            'Исправных',
            'Брак',
            'Брак, %',
            'Исполнители',
        ];
    }

    public function array(): array
    {
        $rows = [];

        $rows[] = ["Участок: {$this->section}"];
        $rows[] = ["Период: {$this->periodLabel}"];
        $rows[] = ['Выгружено: ' . now()->format('d.m.Y H:i')];
        $rows[] = [];

        // Сводка
        $t = $this->totals;
        // Брак/исправные по всем вкладкам не суммируем — устройства разные
        $rows[] = ['ИТОГО по всем вкладкам', $t['total']];
        if (! empty($t['perUser'])) {
            foreach ($t['perUser'] as $name => $count) {
                $rows[] = ['  ' . $name, $count];
            }
        }
        $rows[] = [];

        // По вкладкам
        foreach ($this->perSheet as $data) {
            $total   = $data['total'];
            $defects = $data['defects'] ?? 0;
            $rows[] = [
                $data['title'],
                $total,
                $data['good'] ?? $total,
                $defects,
                $total > 0 ? round($defects / $total * 100, 1) : 0,
                implode(', ', array_map(fn($u) => "{$u['name']} ({$u['count']})", $data['perUser'])) ?: '—',
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}