<?php

namespace App\Console\Commands;

use App\Models\CameraBracketRecord;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportCameraBracket extends Command
{
    protected $signature = 'import:camera-bracket
                            {file : Путь к файлу (xlsx/xls/csv)}
                            {--dry : Проверить без записи в БД}
                            {--user=1 : ID пользователя для created_by}
                            {--allow-unknown : Сохранять реальные имена неизвестных (не заменять на «Уволен»)}
                            {--placeholder=Уволен У У : Замена для пустых/неизвестных ФИО}
                            {--allow-duplicates : Разрешить записывать дубли}';

    protected $description = 'Импорт камер с кронштейном: пустые и неизвестные ФИО → «Уволен У У»';

    private array $workerAliases = [
        'Трушкин Ф.В.' => 'Трушкин Ф.Д.',
        'Карасев А.А.' => 'Карасёв А.А.',
        'Добарин К.В.' => 'Добарин К.',
    ];

    public function handle(): int
    {
        $path = $this->argument('file');
        $dry  = (bool) $this->option('dry');
        $userId = (int) $this->option('user') ?: null;
        $allowUnknown    = (bool) $this->option('allow-unknown');
        $placeholder     = (string) $this->option('placeholder');
        $allowDuplicates = (bool) $this->option('allow-duplicates');

        if (!file_exists($path)) {
            $this->error("Файл не найден: {$path}");
            return self::FAILURE;
        }

        $rows = $this->readRows($path);
        if (empty($rows)) {
            $this->error('Файл пустой или не удалось прочитать.');
            return self::FAILURE;
        }

        $allowedWorkers = Employee::query()
            ->active()
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();

        $surnameMap = [];
        $surnameCount = [];
        foreach ($allowedWorkers as $full) {
            $parts = preg_split('/\s+/u', trim($full), 2);
            $surname = $parts[0] ?? '';
            if ($surname === '') continue;
            $surnameCount[$surname] = ($surnameCount[$surname] ?? 0) + 1;
            $surnameMap[$surname] = $full;
        }
        foreach ($surnameCount as $s => $cnt) {
            if ($cnt > 1) $surnameMap[$s] = null;
        }

        if (!in_array($placeholder, $allowedWorkers, true)) {
            $this->warn("⚠️  «{$placeholder}» не найден в справочнике активных сотрудников.");
            $this->warn("   Добавьте его в /employees/create (галочка «Активен»).");
        }

        $row = 0;
        $lastDate = null;
        $created = 0;
        $skipped = 0;
        $emptyRows = 0;
        $duplicates = 0;
        $errors = [];
        $statSurname = 0;
        $statNormalized = 0;
        $statAlias = 0;
        $statPlaceholder = 0;

        if ($dry) {
            $this->warn('=== РЕЖИМ DRY: изменения НЕ сохраняются ===');
        }
        $this->line('Активных сотрудников в справочнике: ' . count($allowedWorkers));
        $this->line('Пустые и неизвестные ФИО → «' . $placeholder . '»');

        $total = count($rows);
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($rows as $line) {
            $row++;
            if ($row === 1) { $bar->advance(); continue; }

            $isEmpty = true;
            foreach ($line as $cell) {
                if (trim((string) $cell) !== '') { $isEmpty = false; break; }
            }
            if ($isEmpty) {
                $emptyRows++;
                $bar->advance();
                continue;
            }

            $dateRaw     = trim((string) ($line[2]  ?? ''));
            $frameSerial = trim((string) ($line[6]  ?? ''));
            $cam         = trim((string) ($line[7]  ?? ''));
            $nightCam    = trim((string) ($line[8]  ?? ''));
            $speedSensor = trim((string) ($line[9]  ?? ''));
            $notes       = trim((string) ($line[11] ?? ''));
            $w1          = trim((string) ($line[12] ?? ''));
            $w2          = trim((string) ($line[13] ?? ''));
            $w3          = trim((string) ($line[14] ?? ''));
            $comments    = trim((string) ($line[15] ?? ''));

            if ($dateRaw !== '') {
                $parsed = $this->parseDate($dateRaw);
                if ($parsed !== null) {
                    $lastDate = $parsed;
                } else {
                    $errors[] = "Строка {$row}: некорректная дата '{$dateRaw}' — пропущена";
                    $skipped++;
                    $bar->advance();
                    continue;
                }
            }

            if ($lastDate === null) {
                $errors[] = "Строка {$row}: нет даты и нет предыдущей — пропущена";
                $skipped++;
                $bar->advance();
                continue;
            }

            $w1 = $this->cleanName($w1);
            $w2 = $this->cleanName($w2);
            $w3 = $this->cleanName($w3);

            $splitNames = function (?string $v): array {
                if ($v === null) return [];
                return array_values(array_filter(
                    array_map(fn($s) => trim($s), preg_split('/[\/,;]+/u', $v)),
                    fn($s) => $s !== '' && $s !== '-' && $s !== '—'
                ));
            };

            $allWorkers = [];
            foreach ([$w1, $w2, $w3] as $raw) {
                foreach ($splitNames($raw) as $one) {
                    $normalized = $this->normalizeFio($one);
                    if ($normalized !== $one) { $statNormalized++; $one = $normalized; }
                    if (isset($this->workerAliases[$one])) { $one = $this->workerAliases[$one]; $statAlias++; }
                    if ($one !== null && preg_match('/^[А-ЯЁ][а-яё\-]+$/u', $one)) {
                        if (!empty($surnameMap[$one])) { $one = $surnameMap[$one]; $statSurname++; }
                    }
                    if ($one !== null && !in_array($one, $allWorkers, true)) {
                        $allWorkers[] = $one;
                    }
                }
            }
            $allWorkers = array_slice($allWorkers, 0, 5);

            if (empty($allWorkers)) {
                $allWorkers = [$placeholder];
                $statPlaceholder++;
            }

            $w1 = $allWorkers[0] ?? null;
            $w2 = $allWorkers[1] ?? null;
            $w3 = $allWorkers[2] ?? null;
            $w4 = $allWorkers[3] ?? null;
            $w5 = $allWorkers[4] ?? null;

            if (!$allowUnknown) {
                $values = [$w1, $w2, $w3, $w4, $w5];
                foreach ($values as $i => $v) {
                    if ($v !== null && !in_array($v, $allowedWorkers, true)) {
                        $values[$i] = $placeholder;
                        $statPlaceholder++;
                    }
                }
                [$w1, $w2, $w3, $w4, $w5] = $values;
            }

            $payload = [
                'record_date'         => $lastDate,
                'frame_serial'        => $frameSerial === '' ? null : mb_substr($frameSerial, 0, 120),
                'serial_number'       => null,
                'camera_status'       => $cam === '' ? null : $cam,
                'night_camera_status' => $nightCam === '' ? null : $nightCam,
                'speed_sensor_status' => $speedSensor === '' ? null : $speedSensor,
                'suspension_status'   => null,
                'notes'               => $notes === '' ? null : trim(preg_replace('/\s+/u', ' ', $notes)),
                'comments'            => $comments === '' ? null : trim(preg_replace('/\s+/u', ' ', $comments)),
                'worker1'             => $w1,
                'worker2'             => $w2,
                'worker3'             => $w3,
                'worker4'             => $w4,
                'worker5'             => $w5,
                'created_by'          => $userId,
            ];

            if (!$allowDuplicates && $this->isDuplicate($payload)) {
                $duplicates++;
                $bar->advance();
                continue;
            }

            if ($dry) {
                $created++;
                $bar->advance();
                continue;
            }

            try {
                CameraBracketRecord::create($payload);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "Строка {$row}: " . $e->getMessage();
                $skipped++;
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('=== Итог ===');
        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Всего строк в файле',          count($rows)],
                ['Создано записей',              $created],
                ['Пропущено строк (ошибки)',     $skipped],
                ['Пустых строк',                 $emptyRows],
                ['Пропущено дублей',             $duplicates],
                ['Алиасов применено',            $statAlias],
                ['Подставлено по фамилии',       $statSurname],
                ['Нормализовано ФИО',            $statNormalized],
                ['Заменено на «'.$placeholder.'»', $statPlaceholder],
            ]
        );

        if (!empty($errors)) {
            $errPath = storage_path('app/camera_bracket_errors.csv');
            $fhErr = fopen($errPath, 'w');
            fputcsv($fhErr, ['message'], ';');
            foreach ($errors as $e) fputcsv($fhErr, [$e], ';');
            fclose($fhErr);

            $this->newLine();
            $this->warn('=== Ошибки (первые 20 из ' . count($errors) . ') ===');
            foreach (array_slice($errors, 0, 20) as $e) $this->line("  • {$e}");
            if (count($errors) > 20) $this->line('  … и ещё ' . (count($errors) - 20));
            $this->warn("Полный список: {$errPath}");
        }

        if ($dry) {
            $this->newLine();
            $this->warn('=== DRY: изменения НЕ сохранены ===');
        }

        return self::SUCCESS;
    }

    private function readRows(string $path): array
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (in_array($ext, ['csv', 'txt'])) {
            $rows = [];
            $fh = fopen($path, 'r');
            if ($fh === false) return [];
            $bom = fread($fh, 3);
            if ($bom !== "\xEF\xBB\xBF") rewind($fh);
            while (($row = fgetcsv($fh, 0, ';')) !== false) {
                if (count($row) === 1 && str_contains((string) $row[0], ',')) {
                    $row = str_getcsv($row[0], ',');
                }
                $rows[] = array_map(fn($v) => trim((string) $v), $row);
            }
            fclose($fh);
            return $rows;
        }
        try {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $highestRow = $sheet->getHighestDataRow();
            $highestCol = $sheet->getHighestDataColumn();
            $data = $sheet->rangeToArray("A1:{$highestCol}{$highestRow}", null, true, false);
            return array_map(
                fn($row) => array_map(fn($v) => trim((string) $v), $row),
                $data
            );
        } catch (\Throwable $e) {
            $this->error('Ошибка чтения Excel: ' . $e->getMessage());
            return [];
        }
    }

    private function parseDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') return null;

        if (is_numeric($value)) {
            $num = (float) $value;
            if ($num > 1 && $num < 80000) {
                try {
                    return Carbon::create(1899, 12, 30)->addDays((int) $num)->format('Y-m-d');
                } catch (\Throwable $e) {}
            }
        }

        $value = str_replace(["\xC2\xA0", ' г.', ' год'], [' ', '', ''], $value);
        $value = trim($value);

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

    /**
     * Ключ уникальности: дата (±1 день для учёта UTC) + (frame_serial ИЛИ serial_number).
     * Ищем в ОБОИХ полях, потому что старые записи хранят номер в serial_number,
     * а новые — в frame_serial.
     */
    private function isDuplicate(array $data): bool
    {
        $date     = $data['record_date'];
        $prevDate = \Carbon\Carbon::parse($date)->subDay()->toDateString();
        $nextDate = \Carbon\Carbon::parse($date)->addDay()->toDateString();

        $q = CameraBracketRecord::query()
            ->where(function ($w) use ($date, $prevDate, $nextDate) {
                $w->whereDate('record_date', $date)
                  ->orWhereDate('record_date', $prevDate)
                  ->orWhereDate('record_date', $nextDate);
            });

        $key = !empty($data['frame_serial'])
            ? $data['frame_serial']
            : (!empty($data['serial_number']) ? $data['serial_number'] : null);

        // Игнорируем «Б/Н», «—», «-» — это не настоящие серийники
        if ($key === null || in_array(mb_strtoupper(trim($key)), ['Б/Н', 'БН', '—', '-', ''], true)) {
            return false;
        }

        $q->where(function ($w) use ($key) {
            $w->where('frame_serial', $key)
              ->orWhere('serial_number', $key);
        });

        return $q->exists();
    }

    private function cleanName(string $v): ?string
    {
        $v = trim($v);
        if ($v === '' || $v === '-' || $v === '—') return null;
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
        return trim($v);
    }

    private function normalizeFio(?string $v): ?string
    {
        if ($v === null) return null;
        $v = preg_replace('/\s+/u', ' ', trim($v)) ?? $v;
        $v = preg_replace('/([А-ЯЁ])\.\s+([А-ЯЁ])\./u', '$1.$2.', $v) ?? $v;
        if (preg_match('/^(.+?)\s+([А-ЯЁ])\.([А-ЯЁ])$/u', $v, $m)) {
            $v = $m[1] . ' ' . $m[2] . '.' . $m[3] . '.';
        }
        return $v;
    }
}