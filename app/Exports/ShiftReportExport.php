<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/** Отчёт по смене в Excel: по исполнителям и по вкладкам. */
class ShiftReportExport implements FromArray, ShouldAutoSize, WithStyles, WithTitle
{
    private array $boldRows = [];

    public function __construct(private array $data, private Carbon $from, private Carbon $to, private string $section) {}

    public function array(): array
    {
        $rows = [];
        $rows[] = ["Отчёт по смене · {$this->section}"];
        $rows[] = [$this->from->format('d.m.Y H:i') . ' — ' . $this->to->format('d.m.Y H:i')];
        $rows[] = [];

        $rows[] = ['Исполнитель', 'Записей', 'Изделий', 'Брак', '% брака', 'По вкладкам'];
        $this->boldRows[] = count($rows);
        foreach ($this->data['workers'] as $name => $w) {
            $by = collect($w['sheets'])->map(fn ($s, $slug) => ($this->data['sheets'][$slug]['title'] ?? $slug) . ': ' . $s['total'] . ($s['defect'] ? " (брак {$s['defect']})" : ''))->implode('; ');
            $rows[] = [$name, $w['total'], $w['items'], $w['defect'], self::pct($w['defect'], $w['total']), $by];
        }
        $rows[] = [];

        $rows[] = ['Вкладка', 'Записей', 'Изделий', 'Брак', '% брака'];
        $this->boldRows[] = count($rows);
        foreach ($this->data['sheets'] as $s) {
            $rows[] = [$s['title'], $s['total'], $s['items'], $s['defect'], self::pct($s['defect'], $s['total'])];
        }
        $t = $this->data['totals'];
        $rows[] = ['Итого', $t['total'], $t['items'], $t['defect'], self::pct($t['defect'], $t['total'])];
        $this->boldRows[] = count($rows);

        return $rows;
    }

    public static function pct(int $part, int $total): string
    {
        return $total ? round($part / $total * 100, 1) . '%' : '—';
    }

    public function styles(Worksheet $sheet): array
    {
        $styles = [1 => ['font' => ['bold' => true, 'size' => 13]]];
        foreach ($this->boldRows as $r) $styles[$r] = ['font' => ['bold' => true]];

        return $styles;
    }

    public function title(): string
    {
        return 'Смена';
    }
}
