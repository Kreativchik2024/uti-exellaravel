<?php

namespace App\Console\Commands;

use App\Models\PgpRecord;
use Carbon\Carbon;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportPgpRecords extends Command
{
    protected $signature = 'pgp:import
                            {file : Путь к файлу (xlsx/xls/csv)}
                            {--dry-run : Только показать, что будет импортировано, без записи в БД}
                            {--limit=0 : Ограничить количество строк (0 = все)}
                            {--show-empty=5 : Сколько примеров мусорных строк показать (0 = не показывать)}';

    protected $description = 'Импорт ПГП из Excel/CSV с проверкой дублей';

    private const HEADER_MAP = [
        'дата'                          => 'record_date',
        'номер поста'                   => 'post_number',
        'пост'                          => 'post_number',
        'фио 1'                         => 'worker1',
        'фио 2'                         => 'worker2',
        'фио 3'                         => 'worker3',
        'фио 4'                         => 'worker4',
        'фио 5'                         => 'worker5',
        'исполнитель 1'                 => 'worker1',
        'исполнитель 2'                 => 'worker2',
        'исполнитель 3'                 => 'worker3',
        'время начала работ'            => 'work_start',
        'время начала'                  => 'work_start',
        'начало'                        => 'work_start',
        'время завершения'              => 'work_end',
        'время завершения работ'        => 'work_end',
        'окончание'                     => 'work_end',
        'номер борта'                   => 'board_number',
        'борт'                          => 'board_number',
        'ip адрес модуля связи типа manet/mesh' => 'ip_address',
        'ip адрес'                      => 'ip_address',
        'ip'                            => 'ip_address',
        'номер фезюляжа'                => 'fuselage_number',
        'номер фюзеляжа'                => 'fuselage_number',
        'фюзеляж'                       => 'fuselage_number',
        'сдан'                          => '_status_ok',
        'неисправен'                    => '_status_defect',
        'причина неисправности'         => 'defect_reason',
        'действия предпринятые для анализа отказа' => 'actions_taken',
        'действия предпринятые'         => 'actions_taken',
        'действия'                      => 'actions_taken',
    ];

    public function handle(): int
    {
        $path = $this->argument('file');
        if (!is_file($path)) {
            $this->error("Файл не найден: {$path}");
            return self::FAILURE;
        }

        $dryRun    = (bool) $this->option('dry-run');
        $limit     = (int) $this->option('limit');
        $showEmpty = (int) $this->option('show-empty');

        if ($dryRun) {
            $this->warn('⚠️  РЕЖИМ DRY-RUN: данные НЕ будут записаны в БД.');
        }

        $rows = $this->readFile($path);
        if (empty($rows)) {
            $this->error('Не удалось прочитать файл или он пуст.');
            return self::FAILURE;
        }

        $header = array_shift($rows);
        $columnMap = $this->buildColumnMap($header);

        if (empty($columnMap)) {
            $this->error('Не найдено ни одной знакомой колонки.');
            $this->line('Заголовки файла: ' . implode(' | ', array_filter($header)));
            return self::FAILURE;
        }

        $this->info('Распознаны колонки:');
        foreach ($columnMap as $excelIdx => $dbField) {
            $this->line("  • [{$excelIdx}] " . ($header[$excelIdx] ?? '') . " → {$dbField}");
        }
        $this->newLine();

        $created     = 0;
        $skipped     = 0;
        $emptyRows   = 0;
        $noDateRows  = 0;
        $errorLog    = [];
        $problemRows = [];
        $emptyRowSamples = []; // примеры мусорных строк

        $bar = $this->output->createProgressBar(count($rows));
        $bar->start();

        foreach ($rows as $rowNum => $row) {
            if ($limit > 0 && $created + $skipped >= $limit) break;

            $realRowNumber = $rowNum + 2;

            $data = $this->mapRow($row, $columnMap);

            // Мусорная/пустая строка
            if ($data === null) {
                $emptyRows++;
                if (count($emptyRowSamples) < $showEmpty) {
                    $emptyRowSamples[] = [
                        'row' => $realRowNumber,
                        'raw' => array_slice($row, 0, max(15, count($row))),
                    ];
                }
                $bar->advance();
                continue;
            }

            // Нет даты — проблема
            if (empty($data['record_date'])) {
                $noDateRows++;
                if (count($problemRows) < 5) {
                    $problemRows[] = [
                        'row'  => $realRowNumber,
                        'data' => array_slice($row, 0, 7),
                    ];
                }
                $bar->advance();
                continue;
            }

            if ($this->isDuplicate($data)) {
                $skipped++;
                $bar->advance();
                continue;
            }

            if (!$dryRun) {
                try {
                    PgpRecord::create(array_merge($data, [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]));
                } catch (\Throwable $e) {
                    $errorLog[] = "Строка {$realRowNumber}: " . $e->getMessage();
                    $bar->advance();
                    continue;
                }
            }

            $created++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Всего строк в файле',        count($rows)],
                ['Добавлено новых',            $created],
                ['Пропущено (дубли)',          $skipped],
                ['Пустых/мусорных строк',      $emptyRows],
                ['Строк без даты',             $noDateRows],
                ['Ошибок сохранения',          count($errorLog)],
            ]
        );

        // Примеры мусорных строк — что там реально?
        if ($showEmpty > 0 && !empty($emptyRowSamples)) {
            $this->newLine();
            $this->warn("Примеры «мусорных» строк (первые {$showEmpty}):");
            $this->line('Посмотрите: если там есть борт / ФИО / пост — эти данные теряются.');
            $this->newLine();
            foreach ($emptyRowSamples as $s) {
                $cells = [];
                foreach ($s['raw'] as $i => $cell) {
                    if (trim((string) $cell) !== '') {
                        $cells[] = "[{$i}]=" . mb_substr((string) $cell, 0, 30);
                    }
                }
                $this->line("  Строка {$s['row']}: " . (empty($cells) ? '(пусто)' : implode('  ', $cells)));
            }
        }

        if (!empty($problemRows)) {
            $this->newLine();
            $this->warn('Примеры строк БЕЗ ДАТЫ (первые 5):');
            foreach ($problemRows as $p) {
                $this->line("  Строка {$p['row']}: " . implode(' | ', array_map(fn($v) => mb_substr((string) $v, 0, 40), $p['data'])));
            }
        }

        if (!empty($errorLog)) {
            $this->newLine();
            $this->warn('Ошибки сохранения (первые 20):');
            foreach (array_slice($errorLog, 0, 20) as $err) {
                $this->line('  ' . $err);
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->warn("Это был DRY-RUN. Чтобы реально записать данные, запустите:");
            $this->line("  php artisan pgp:import \"{$path}\"");
        }

        return self::SUCCESS;
    }

    private function readFile(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (in_array($ext, ['csv', 'txt'])) {
            $rows = [];
            $handle = fopen($path, 'r');
            if ($handle === false) return [];

            $bom = fread($handle, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($handle);

            while (($row = fgetcsv($handle, 0, ';')) !== false) {
                if (count($row) === 1 && str_contains((string) $row[0], ',')) {
                    $row = str_getcsv($row[0], ',');
                }
                $rows[] = array_map(fn($v) => trim((string) $v), $row);
            }
            fclose($handle);
            return $rows;
        }

        try {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
            return array_map(
                fn($row) => array_map(fn($v) => trim((string) $v), $row),
                $rows
            );
        } catch (\Throwable $e) {
            $this->error('Ошибка чтения Excel: ' . $e->getMessage());
            return [];
        }
    }

    private function buildColumnMap(array $header): array
    {
        $map = [];
        foreach ($header as $idx => $title) {
            $key = $this->normalizeHeader($title);
            if ($key === '') continue;
            if (isset(self::HEADER_MAP[$key])) {
                $map[$idx] = self::HEADER_MAP[$key];
            }
        }
        return $map;
    }

    private function normalizeHeader(string $title): string
    {
        $title = mb_strtolower(trim($title), 'UTF-8');
        $title = str_replace(["\xC2\xA0", "\n", "\r", "\t"], ' ', $title);
        $title = preg_replace('/\s+/u', ' ', $title);
        return trim($title);
    }

    private function mapRow(array $row, array $columnMap): ?array
    {
        $data = [
            'record_date'     => null,
            'post_number'     => null,
            'worker1'         => null,
            'worker2'         => null,
            'worker3'         => null,
            'worker4'         => null,
            'worker5'         => null,
            'work_start'      => null,
            'work_end'        => null,
            'board_number'    => null,
            'ip_address'      => null,
            'fuselage_number' => null,
            'defect_reason'   => null,
            'actions_taken'   => null,
            'status'          => 'Сдан',
        ];

        $statusOk     = false;
        $statusDefect = false;
        $hasAnyData   = false;
        $hasImportant = false; // дата, борт, ФИО или пост

        foreach ($columnMap as $idx => $field) {
            $value = $row[$idx] ?? '';
            if ($value === '' || $value === null) continue;

            switch ($field) {
                case 'record_date':
                    $data['record_date'] = $this->parseDate($value);
                    if ($data['record_date']) { $hasAnyData = true; $hasImportant = true; }
                    break;
                case 'post_number':
                    $data[$field] = is_numeric($value) ? (int) $value : null;
                    if ($data[$field]) { $hasAnyData = true; $hasImportant = true; }
                    break;
                case 'work_start':
                case 'work_end':
                    $data[$field] = $this->parseTime($value);
                    if ($data[$field]) $hasAnyData = true;
                    break;
                case '_status_ok':
                    $statusOk = $this->isChecked($value);
                    if ($statusOk) $hasAnyData = true;
                    break;
                case '_status_defect':
                    $statusDefect = $this->isChecked($value);
                    if ($statusDefect) $hasAnyData = true;
                    break;
                case 'worker1':
                case 'worker2':
                case 'worker3':
                case 'worker4':
                case 'worker5':
                case 'board_number':
                    $data[$field] = $value;
                    $hasAnyData = true;
                    $hasImportant = true;
                    break;
                case 'ip_address':
                case 'fuselage_number':
                case 'defect_reason':
                case 'actions_taken':
                    $data[$field] = $value;
                    $hasAnyData = true;
                    break;
            }
        }

        // Если нет ни даты, ни поста, ни борта, ни ФИО — это мусор
        if (!$hasImportant) {
            return null;
        }

        if ($statusDefect) {
            $data['status'] = 'Неисправен';
        } elseif ($statusOk) {
            $data['status'] = 'Сдан';
        }

        if (empty($data['worker1'])) {
            $data['worker1'] = 'Не указан';
        }

        $data['worker1']  = mb_substr((string) $data['worker1'], 0, 120);
        $data['worker2']  = $data['worker2'] ? mb_substr((string) $data['worker2'], 0, 120) : null;
        $data['worker3']  = $data['worker3'] ? mb_substr((string) $data['worker3'], 0, 120) : null;
        $data['worker4']  = $data['worker4'] ? mb_substr((string) $data['worker4'], 0, 120) : null;
        $data['worker5']  = $data['worker5'] ? mb_substr((string) $data['worker5'], 0, 120) : null;
        $data['board_number']    = $data['board_number']    ? mb_substr((string) $data['board_number'], 0, 80)  : null;
        $data['ip_address']      = $data['ip_address']      ? mb_substr((string) $data['ip_address'], 0, 64)    : null;
        $data['fuselage_number'] = $data['fuselage_number'] ? mb_substr((string) $data['fuselage_number'], 0, 64) : null;

        return $data;
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;

        $value = str_replace(["\xC2\xA0", ' г.', ' год'], [' ', '', ''], $value);
        $value = trim($value);

        if (ctype_digit($value) && (int) $value > 20000 && (int) $value < 80000) {
            try {
                return Carbon::create(1900, 1, 1)->addDays((int) $value - 2)->format('Y-m-d');
            } catch (\Throwable $e) {}
        }

        foreach (['d.m.Y', 'd/m/Y', 'Y-m-d', 'd-m-Y', 'd.m.y', 'j.n.Y'] as $fmt) {
            try {
                $dt = Carbon::createFromFormat($fmt, $value);
                if ($dt) return $dt->format('Y-m-d');
            } catch (\Throwable $e) {}
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function parseTime(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;

        foreach (['H:i:s', 'H:i', 'H.i', 'H.i.s'] as $fmt) {
            try {
                return Carbon::createFromFormat($fmt, $value)->format('H:i:s');
            } catch (\Throwable $e) {}
        }
        return null;
    }

    private function isChecked(string $value): bool
    {
        $v = mb_strtolower(trim($value));
        return in_array($v, ['1', 'да', 'yes', 'true', 'v', '✓', '+', 'x', 'х', 'сдан'], true);
    }

    private function isDuplicate(array $data): bool
    {
        $q = PgpRecord::query()
            ->where('record_date', $data['record_date'])
            ->where('post_number', $data['post_number']);

        if (!empty($data['board_number'])) {
            $q->where('board_number', $data['board_number']);
        }

        if (!empty($data['work_start'])) {
            $q->where('work_start', $data['work_start']);
        }

        return $q->exists();
    }
}