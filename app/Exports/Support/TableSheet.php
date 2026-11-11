<?php

namespace App\Exports\Support;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Один лист Excel в общем оформлении всех выгрузок:
 *
 *   Заголовок (крупно)
 *   Участок:   …            ← блок сведений «подпись — значение»
 *   Период:    …
 *
 *   Подпись таблицы
 *   | Шапка | Шапка | …     ← заливка, жирный, закреплена, фильтр
 *   | …     | …     |       ← данные с рамками, числа — числами
 *   | Итого | …     |       ← жирная строка итогов
 *
 * Колонка: ['title' => 'Брак, %', 'type' => 'text|int|num|pct|date|datetime', 'width' => 12, 'wrap' => true].
 * pct — доля 0..1 (в ячейке будет «12,5 %»), date/datetime — Carbon/строка Y-m-d.
 * Строки — массивы значений по порядку колонок; null — пустая ячейка.
 */
class TableSheet implements FromArray, WithEvents, WithTitle
{
    private const HEAD_FILL  = 'FFE0E7FF';
    private const TOTAL_FILL = 'FFF1F5F9';
    private const BORDER     = 'FFCBD5E1';

    /**
     * @param array $info   [['Участок', 'Сборка'], ['Период', '01.11 — 07.11']]
     * @param array $tables [['caption' => ?string, 'columns' => [...], 'rows' => [...], 'total' => ?array, 'empty' => ?string]]
     */
    public function __construct(
        private string $sheetTitle,
        private ?string $heading,
        private array $info,
        private array $tables,
    ) {}

    public static function make(string $sheetTitle, ?string $heading, array $info, array $tables): self
    {
        return new self($sheetTitle, $heading, $info, $tables);
    }

    public function title(): string
    {
        // Excel: до 31 символа, без []:*?/\
        return mb_substr(preg_replace('/[\[\]:*?\/\\\\]/u', ' ', $this->sheetTitle), 0, 31);
    }

    public function array(): array
    {
        return [];   // лист заполняется в registerEvents — там же оформление
    }

    public function registerEvents(): array
    {
        return [AfterSheet::class => fn (AfterSheet $e) => $this->render($e->sheet->getDelegate())];
    }

    public function render(Worksheet $ws): void
    {
        $row = 1;
        $widths = [];
        $maxCols = max(array_map(fn ($t) => count($t['columns']), $this->tables) ?: [1]);

        if ($this->heading !== null) {
            $this->put($ws, 1, $row, $this->heading);
            $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(14);
            $row++;
        }
        foreach ($this->info as [$label, $value]) {
            $this->put($ws, 1, $row, $label);
            $this->put($ws, 2, $row, $value);
            $ws->getStyle("A{$row}")->getFont()->setBold(true);
            $ws->getStyle("A{$row}:B{$row}")->getFont()->getColor()->setARGB('FF475569');
            $row++;
        }
        if ($this->heading !== null || $this->info) $row++;

        $frozen = false;
        $single = count($this->tables) === 1;
        foreach ($this->tables as $t) {
            $cols = $t['columns'];
            $n = count($cols);
            $last = Coordinate::stringFromColumnIndex($n);

            if (! empty($t['caption'])) {
                $this->put($ws, 1, $row, $t['caption']);
                $ws->getStyle("A{$row}")->getFont()->setBold(true)->setSize(12);
                $row++;
            }

            // Шапка
            $head = $row;
            foreach ($cols as $i => $c) {
                $this->put($ws, $i + 1, $row, $c['title']);
                $widths[$i] = max($widths[$i] ?? 0, mb_strlen($c['title']) + 2);
            }
            $ws->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
                'font' => ['bold' => true],
                'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::HEAD_FILL]],
                'alignment' => ['vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
            ]);
            if (! $frozen) {   // при прокрутке шапка первой таблицы остаётся на месте
                $ws->freezePane('A' . ($row + 1));
                $frozen = true;
            }
            $row++;

            // Данные
            $first = $row;
            $rows = $t['rows'];
            if (! $rows && ! empty($t['empty'])) {
                $this->put($ws, 1, $row, $t['empty']);
                $ws->mergeCells("A{$row}:{$last}{$row}");
                $ws->getStyle("A{$row}")->getFont()->setItalic(true)->getColor()->setARGB('FF64748B');
                $row++;
            }
            foreach ($rows as $r) {
                $this->writeRow($ws, $row, $cols, array_values($r), $widths);
                $row++;
            }
            $lastData = $row - 1;

            // Итого
            if (! empty($t['total'])) {
                $this->writeRow($ws, $row, $cols, array_values($t['total']), $widths);
                $ws->getStyle("A{$row}:{$last}{$row}")->applyFromArray([
                    'font' => ['bold' => true],
                    'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => self::TOTAL_FILL]],
                    'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FF94A3B8']]],
                ]);
                $row++;
            }

            $ws->getStyle("A{$head}:{$last}" . ($row - 1))->getBorders()->getAllBorders()
                ->setBorderStyle(Border::BORDER_THIN)->getColor()->setARGB(self::BORDER);
            $ws->getStyle("A{$first}:{$last}" . max($first, $row - 1))->getAlignment()->setVertical(Alignment::VERTICAL_TOP);

            // Фильтр — если таблица на листе одна (у Excel один автофильтр на лист)
            if ($single && $rows) $ws->setAutoFilter("A{$head}:{$last}{$lastData}");

            $row += 2;
        }

        // Ширины: по содержимому, длинный текст — с переносом
        for ($i = 0; $i < $maxCols; $i++) {
            $fixed = null;
            foreach ($this->tables as $t) $fixed ??= $t['columns'][$i]['width'] ?? null;
            $w = $fixed ?? min(60, max(8, $widths[$i] ?? 10));
            $ws->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($w);
            foreach ($this->tables as $t) {
                if (($t['columns'][$i]['wrap'] ?? false) || ($fixed === null && ($widths[$i] ?? 0) > 60)) {
                    $ws->getStyle(Coordinate::stringFromColumnIndex($i + 1) . '1:' . Coordinate::stringFromColumnIndex($i + 1) . $row)->getAlignment()->setWrapText(true);
                    break;
                }
            }
        }
        // Первая колонка не уже подписей блока сведений
        $infoW = max(array_map(fn ($x) => mb_strlen((string) $x[0]) + 2, $this->info) ?: [0]);
        if ($infoW > ($ws->getColumnDimension('A')->getWidth())) $ws->getColumnDimension('A')->setWidth(min(30, $infoW));

        $ws->getPageSetup()->setFitToWidth(1)->setFitToHeight(0);
        $ws->setSelectedCell('A1');
    }

    private function writeRow(Worksheet $ws, int $row, array $cols, array $values, array &$widths): void
    {
        foreach ($cols as $i => $c) {
            $v = $values[$i] ?? null;
            if ($v === null || $v === '') continue;
            $coord = Coordinate::stringFromColumnIndex($i + 1) . $row;
            $type = $c['type'] ?? 'text';

            if (in_array($type, ['int', 'num', 'pct'], true) && is_numeric($v)) {
                $ws->getCell($coord)->setValueExplicit($v + 0, DataType::TYPE_NUMERIC);
                $ws->getStyle($coord)->getNumberFormat()->setFormatCode(match ($type) {
                    'int' => '#,##0', 'num' => '#,##0.0', 'pct' => '0.0%',
                });
                $len = 8;
            } elseif (in_array($type, ['date', 'datetime'], true) && ($ts = $this->timestamp($v)) !== null) {
                $ws->getCell($coord)->setValueExplicit(\PhpOffice\PhpSpreadsheet\Shared\Date::PHPToExcel($ts), DataType::TYPE_NUMERIC);
                $ws->getStyle($coord)->getNumberFormat()->setFormatCode($type === 'date' ? 'dd.mm.yyyy' : 'dd.mm.yyyy hh:mm');
                $len = $type === 'date' ? 11 : 17;
            } else {
                $this->put($ws, $i + 1, $row, $v);
                $len = max(array_map('mb_strlen', explode("\n", (string) $v)));
                if (str_contains((string) $v, "\n")) $ws->getStyle($coord)->getAlignment()->setWrapText(true);
            }
            $widths[$i] = max($widths[$i] ?? 0, $len + 2);
        }
    }

    /** Текст всегда как текст: «=…», «+7…», «00123» не превращаются в формулы и числа. */
    private function put(Worksheet $ws, int $col, int $row, mixed $v): void
    {
        if ($v === null) return;
        $ws->getCell(Coordinate::stringFromColumnIndex($col) . $row)->setValueExplicit(
            is_bool($v) ? ($v ? 'Да' : 'Нет') : (string) $v, DataType::TYPE_STRING);
    }

    private function timestamp(mixed $v): ?int
    {
        if ($v instanceof \DateTimeInterface) return $v->getTimestamp() + $v->getOffset();
        try {
            $d = \Carbon\Carbon::parse((string) $v);
            return $d->getTimestamp() + $d->getOffset();
        } catch (\Throwable) {
            return null;
        }
    }
}
