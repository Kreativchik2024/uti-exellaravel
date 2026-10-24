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
            'Всего записей',
            'Исполнители',
        ];
    }

    public function array(): array
    {
        $rows = [];

        $rows[] = ["Период: {$this->period} дн. (с {$this->from->format('d.m.Y')} по " . now()->format('d.m.Y') . ")", '', ''];
        $rows[] = ['', '', ''];

        // Сводка
        $rows[] = ['ИТОГО по всем вкладкам', $this->totals['total'], ''];
        if (! empty($this->totals['perUser'])) {
            foreach ($this->totals['perUser'] as $name => $count) {
                $rows[] = ['  ' . $name, $count, ''];
            }
        }
        $rows[] = ['', '', ''];

        // По вкладкам
        foreach ($this->perSheet as $data) {
            $rows[] = [
                $data['title'],
                $data['total'],
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