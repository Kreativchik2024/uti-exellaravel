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
        if ($ext === 'ods') return self::sheetsFromPath($file->getRealPath(), $ext);
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

    /**
     * Все листы файла на диске (xlsx / xls / ods / csv): [название листа => строки].
     * Значения «сырые»: даты и время — числа Excel (как и при чтении через Laravel Excel).
     */
    public static function sheetsFromPath(string $path, string $ext): array
    {
        $ext = strtolower($ext);
        if (! in_array($ext, ['xlsx', 'xls', 'ods'], true)) {
            return ['CSV' => self::csvText((string) file_get_contents($path))];
        }

        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader(ucfirst($ext));
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $out = [];
        foreach ($book->getWorksheetIterator() as $ws) {
            $out[$ws->getTitle()] = $ws->toArray(null, true, false, false);
        }
        $book->disconnectWorksheets();

        return $out;
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
