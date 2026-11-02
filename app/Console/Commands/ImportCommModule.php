<?php

namespace App\Console\Commands;

use App\Models\CommModuleRecord;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ImportCommModule extends Command
{
    protected $signature = 'import:comm-module
                            {file : Путь к файлу (xlsx/xls/csv)}
                            {--dry : Проверить без записи в БД}
                            {--user=1 : ID пользователя для created_by}
                            {--allow-unknown : Не пропускать строки с неизвестными исполнителями}
                            {--placeholder= : Текст для пустого worker1}
                            {--allow-duplicates : Разрешить записывать дубли}';

    protected $description = 'Импорт модулей связи из Excel/CSV в comm_module_records';

    private array $workerAliases = [
        'Трушкин Ф.В.' => 'Трушкин Ф.Д.',
        'Карасев А.А.' => 'Карасёв А.А.',
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

        $row = 0;
        $lastDate = null;
        $created = 0;
        $skipped = 0;
        $duplicates = 0;
        $errors = [];
        $statSurname = 0;
        $statNormalized = 0;
        $statAlias = 0;

        if ($dry) {
            $this->warn('=== РЕЖИМ DRY: изменения НЕ сохраняются ===');
        }
        $this->line('Активных сотрудников в справочнике: ' . count($allowedWorkers));
        if (!empty($this->workerAliases)) {
            $this->line('Алиасов задано: ' . count($this->workerAliases));
        }
        if ($allowDuplicates) {
            $this->warn('⚠️  Дубли РАЗРЕШЕНЫ (защита отключена)');
        }

        // Прогресс-бар
        $total = count($rows);
        $bar = $this->output->createProgressBar($total);
        $bar->start();

        foreach ($rows as $line) {
            $row++;

            if ($row === 1) { $bar->advance(); continue; } // заголовок

            if (count($line) < 3 && trim(implode('', $line)) === '') {
                $bar->advance();
                continue;
            }

            // Колонки: [0]№ [1]Дата [2]Кол-во [3]ФИО1 [4]ФИО2 [5]IP [6]S/N [7]Примечание
            $num   = trim((string) ($line[0] ?? ''));
            $date  = trim((string) ($line[1] ?? ''));
            $w1    = trim((string) ($line[3] ?? ''));
            $w2    = trim((string) ($line[4] ?? ''));
            $ip    = trim((string) ($line[5] ?? ''));
            $sn    = trim((string) ($line[6] ?? ''));
            $notes = trim((string) ($line[7] ?? ''));

            if ($date !== '') {
                try {
                    $dt = Carbon::createFromFormat('d.m.Y', $date);
                    if ($dt === false) throw new \Exception('bad date');
                    $lastDate = $dt->toDateString();
                } catch (\Throwable $e) {
                    $errors[] = "Строка {$num}: некорректная дата '{$date}' — пропущена";
                    $skipped++;
                    $bar->advance();
                    continue;
                }
            }

            if ($lastDate === null) {
                $errors[] = "Строка {$num}: нет даты и нет предыдущей — пропущена";
                $skipped++;
                $bar->advance();
                continue;
            }

            $w1 = $this->cleanName($w1);
            $w2 = $this->cleanName($w2);

            if ($w1 === null && $placeholder === '') {
                $errors[] = "Строка {$num}: пустое ФИО 1 — пропущена";
                $skipped++;
                $bar->advance();
                continue;
            }

            $splitNames = function (?string $v): array {
                if ($v === null) return [];
                return array_values(array_filter(
                    array_map(fn($s) => trim($s), preg_split('/[\/,;]+/u', $v)),
                    fn($s) => $s !== '' && $s !== '-' && $s !== '—'
                ));
            };

            $allWorkers = [];
            foreach ([$w1, $w2] as $raw) {
                foreach ($splitNames($raw) as $one) {
                    $normalized = $this->normalizeFio($one);
                    if ($normalized !== $one) {
                        $statNormalized++;
                        $one = $normalized;
                    }
                    if (isset($this->workerAliases[$one])) {
                        $one = $this->workerAliases[$one];
                        $statAlias++;
                    }
                    if ($one !== null && preg_match('/^[А-ЯЁ][а-яё\-]+$/u', $one)) {
                        if (!empty($surnameMap[$one])) {
                            $one = $surnameMap[$one];
                            $statSurname++;
                        }
                    }
                    if ($one !== null && !in_array($one, $allWorkers, true)) {
                        $allWorkers[] = $one;
                    }
                }
            }
            $allWorkers = array_slice($allWorkers, 0, 5);
            $w1 = $allWorkers[0] ?? null;
            $w2 = $allWorkers[1] ?? null;
            $w3 = $allWorkers[2] ?? null;
            $w4 = $allWorkers[3] ?? null;
            $w5 = $allWorkers[4] ?? null;

            if ($w1 === null && $placeholder !== '') $w1 = $placeholder;

            $bad = array_values(array_filter(
                array_filter([$w1, $w2, $w3, $w4, $w5], fn($w) => $w !== null),
                fn($w) => !in_array($w, $allowedWorkers, true)
            ));
            if (!empty($bad) && !$allowUnknown) {
                $errors[] = "Строка {$num}: исполнители не из справочника: " . implode(', ', array_unique($bad)) . " — пропущена";
                $skipped++;
                $bar->advance();
                continue;
            }

            $ip = $ip === '' ? null : mb_substr($ip, 0, 64);
            $notes = $notes === '' ? null : trim(preg_replace('/\s+/u', ' ', $notes));

            $snList = [];
            if ($sn === '') {
                $snList = [null];
            } else {
                foreach (preg_split('/\r?\n/', $sn) as $part) {
                    $part = trim($part);
                    if ($part !== '') $snList[] = $part;
                }
                if (empty($snList)) $snList = [null];
            }

            foreach ($snList as $snItem) {
                $payload = [
                    'record_date'   => $lastDate,
                    'serial_number' => $snItem ?? '—',
                    'ip_address'    => $ip,
                    'worker1'       => $w1,
                    'worker2'       => $w2,
                    'worker3'       => $w3,
                    'worker4'       => $w4,
                    'worker5'       => $w5,
                    'notes'         => $notes,
                    'created_by'    => $userId,
                ];

                if (!$allowDuplicates && $this->isDuplicate($payload)) {
                    $duplicates++;
                    continue;
                }

                if ($dry) {
                    $created++;
                    continue;
                }

                try {
                    CommModuleRecord::create($payload);
                    $created++;
                } catch (\Throwable $e) {
                    $errors[] = "Строка {$num}, S/N '{$snItem}': " . $e->getMessage();
                    $skipped++;
                }
            }

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info('=== Итог ===');
        $this->table(
            ['Метрика', 'Значение'],
            [
                ['Всего строк в файле', count($rows)],
                ['Создано записей',     $created],
                ['Пропущено строк',     $skipped],
                ['Пропущено дублей',    $duplicates],
                ['Алиасов применено',   $statAlias],
                ['Подставлено по фамилии', $statSurname],
                ['Нормализовано ФИО',   $statNormalized],
            ]
        );

        if (!empty($errors)) {
            $errPath = storage_path('app/manet_errors.csv');
            $fhErr = fopen($errPath, 'w');
            fputcsv($fhErr, ['message'], ';');
            foreach ($errors as $e) fputcsv($fhErr, [$e], ';');
            fclose($fhErr);

            $this->newLine();
            $this->warn('=== Предупреждения (первые 20 из ' . count($errors) . ') ===');
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

    /**
     * Читает файл и возвращает массив строк (каждая строка — массив ячеек).
     * Поддерживает CSV и XLSX/XLS.
     */
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

        // xlsx / xls
        try {
            $spreadsheet = IOFactory::load($path);
            $sheet = $spreadsheet->getActiveSheet();
            $data = $sheet->toArray(null, true, true, false);
            return array_map(
                fn($row) => array_map(fn($v) => trim((string) $v), $row),
                $data
            );
        } catch (\Throwable $e) {
            $this->error('Ошибка чтения Excel: ' . $e->getMessage());
            return [];
        }
    }

    private function isDuplicate(array $data): bool
    {
        $q = CommModuleRecord::query()->where('record_date', $data['record_date']);

        if (!empty($data['serial_number']) && $data['serial_number'] !== '—') {
            $q->where('serial_number', $data['serial_number']);
        } elseif (!empty($data['ip_address'])) {
            $q->where('ip_address', $data['ip_address']);
        } else {
            return false;
        }

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