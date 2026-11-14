<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Concerns\ToArray;
use Maatwebsite\Excel\Facades\Excel;

/** Строки загружаемого файла: Excel / LibreOffice (.ods, первый лист) или CSV в UTF-8 / Windows-1251 с любым разделителем. */
class TableReader
{
    public static function rows(UploadedFile $file): array
    {
        return array_values(self::sheets($file))[0] ?? [];
    }

    /**
     * Все листы: [название листа => строки]. У CSV один лист «CSV».
     */
    public static function sheets(UploadedFile $file): array
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if ($ext === 'ods') {
            $out = [];
            // старым импортам (сотрудники и т.п.) — как раньше: строки подряд, пустые ячейки — null
            foreach (self::sheetNamesFromPath($file->getRealPath(), $ext) as $name) {
                $rows = self::sheetFromPath($file->getRealPath(), $ext, $name);
                $width = $rows ? max(array_map(fn ($r) => $r ? max(array_keys($r)) + 1 : 0, $rows)) : 0;
                $dense = [];
                if ($rows) {
                    for ($i = 0, $last = max(array_keys($rows)); $i <= $last; $i++) {
                        $dense[] = $width ? array_replace(array_fill(0, $width, null), $rows[$i] ?? []) : [];
                    }
                }
                $out[$name] = $dense;
            }
            return $out;
        }
        if (in_array($ext, ['xlsx', 'xls'], true)) {
            $sheets = Excel::toArray(new class implements ToArray { public function array(array $array): void {} }, $file);
            try {
                $names = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($file->getRealPath())->listWorksheetNames($file->getRealPath());
            } catch (\Throwable) {
                $names = [];
            }
            $out = [];
            foreach ($sheets as $i => $rows) $out[$names[$i] ?? 'Лист ' . ($i + 1)] = $rows;

            return $out;
        }

        return ['CSV' => self::csv($file)];
    }

    /** Сколько колонок читаем: в журналах их пара десятков, а пустые оформленные колонки до AMJ съедают память. */
    public const MAX_COLUMNS = 60;

    /** Названия листов файла на диске (xlsx / xls / ods; у csv — один лист «CSV»), без чтения содержимого. */
    public static function sheetNamesFromPath(string $path, string $ext): array
    {
        $ext = strtolower($ext);
        if (! in_array($ext, ['xlsx', 'xls', 'ods'], true)) return ['CSV'];
        if ($ext === 'ods') return OdsReader::sheetNames($path);

        return \PhpOffice\PhpSpreadsheet\IOFactory::createReader(ucfirst($ext))->listWorksheetNames($path);
    }

    /**
     * Строки одного листа файла на диске. $maxRows — прочитать только начало (для поиска заголовков).
     * Читается только этот лист, только первые MAX_COLUMNS колонок и без пустых ячеек — иначе .ods из LibreOffice
     * (с «повторёнными» пустыми строками и колонками) не помещается в память небольшого сервера.
     * Значения «сырые»: даты и время — числа Excel (как и при чтении через Laravel Excel).
     */
    public static function sheetFromPath(string $path, string $ext, string $sheet, ?int $maxRows = null): array
    {
        $ext = strtolower($ext);
        if (! in_array($ext, ['xlsx', 'xls', 'ods'], true)) {
            $rows = self::csvText((string) file_get_contents($path));
            return $maxRows ? array_slice($rows, 0, $maxRows) : $rows;
        }
        if ($ext === 'ods') return OdsReader::sheet($path, $sheet, $maxRows, self::MAX_COLUMNS);

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader(ucfirst($ext));
        $reader->setReadDataOnly(true);
        $reader->setReadEmptyCells(false);
        $reader->setLoadSheetsOnly([$sheet]);
        $reader->setReadFilter(new class($maxRows) implements \PhpOffice\PhpSpreadsheet\Reader\IReadFilter {
            public function __construct(private ?int $maxRows) {}
            public function readCell(string $columnAddress, int $row, string $worksheetName = ''): bool
            {
                return ($this->maxRows === null || $row <= $this->maxRows)
                    && \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($columnAddress) <= TableReader::MAX_COLUMNS;
            }
        });

        $book = $reader->load($path);
        $ws = $book->getSheetByName($sheet) ?? $book->getActiveSheet();
        $lastRow = $ws->getHighestDataRow();
        $lastCol = $ws->getHighestDataColumn();
        $rows = $lastRow > 0 ? $ws->rangeToArray("A1:{$lastCol}{$lastRow}", null, true, false, false) : [];
        $book->disconnectWorksheets();
        unset($book, $ws);

        return $rows;
    }

    private static function csv(UploadedFile $file): array
    {
        return self::csvText((string) file_get_contents($file->getRealPath()));
    }

    private static function csvText(string $text): array
    {
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (! mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'Windows-1251');
        }
        $lines = preg_split('/\R/u', trim($text));
        $first = $lines[0] ?? '';
        $delimiter = collect([';' => substr_count($first, ';'), "\t" => substr_count($first, "\t"), ',' => substr_count($first, ',')])
            ->sortDesc()->keys()->first();
        if (substr_count($first, $delimiter) === 0) $delimiter = ';';

        return array_map(fn ($l) => str_getcsv($l, $delimiter), array_filter($lines, fn ($l) => trim($l) !== ''));
    }
}
