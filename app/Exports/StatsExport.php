<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class StatsExport implements FromArray, WithHeadings, WithStyles, WithTitle
{
    public function __construct(
        protected int $period,
        protected Carbon $from,
        protected array $perSheet,
        protected array $totals,
    ) {}

    public function title(): string
    {
        return "Статистика {$this->period} дн.";
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

        $rows[] = ["Период: {$this->period} дн. (с {$this->from->format('d.m.Y')} по " . now()->format('d.m.Y') . ")"];
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