<?php

namespace App\Console\Commands;

use App\Models\CommModuleRecord;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ImportCommModule extends Command
{
    protected $signature = 'import:comm-module
                            {file : Путь к CSV-файлу}
                            {--dry : Проверить без записи в БД}
                            {--user=1 : ID пользователя для created_by}';

    protected $description = 'Импорт модулей связи из CSV (manet.csv) в comm_module_records';

    /**
     * Ручные алиасы: CSV-значение => short_name в справочнике.
     * Нужны, если в CSV опечатка или нестандартное написание.
     */
    private array $workerAliases = [
        // 'Трушкин Ф.В.' => 'Трушкин Ф. В.',
    ];

    public function handle(): int
    {
        $path = $this->argument('file');
        $dry  = (bool) $this->option('dry');
        $userId = (int) $this->option('user') ?: null;

        if (!file_exists($path)) {
            $this->error("Файл не найден: {$path}");
            return self::FAILURE;
        }

        $fh = fopen($path, 'r');
        if ($fh === false) {
            $this->error('Не удалось открыть файл');
            return self::FAILURE;
        }

        $allowedWorkers = Employee::query()
            ->active()
            ->orderBy('short_name')
            ->pluck('short_name')
            ->all();

        // ---- Карта: фамилия → единственный активный сотрудник ----
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
            if ($cnt > 1) {
                $surnameMap[$s] = null; // неоднозначно
            }
        }

        $row = 0;
        $lastDate = null;
        $created = 0;
        $skipped = 0;
        $errors = [];
        $statSurname = 0; // сколько подставлено по фамилии
        $statNormalized = 0; // сколько нормализовано инициалов

        if ($dry) {
            $this->warn('=== РЕЖИМ DRY: изменения НЕ сохраняются ===');
        }
        $this->line('Активных сотрудников в справочнике: ' . count($allowedWorkers));
        if (!empty($this->workerAliases)) {
            $this->line('Алиасов задано: ' . count($this->workerAliases));
        }

        while (($line = fgetcsv($fh, 0, ';')) !== false) {
            $row++;

            if ($row === 1) {
                continue;
            }

            if (count($line) < 3 && trim(implode('', $line)) === '') {
                continue;
            }

            $num   = trim((string) ($line[0]  ?? ''));
            $date  = trim((string) ($line[1]  ?? ''));
            $w1    = trim((string) ($line[3]  ?? ''));
            $w2    = trim((string) ($line[4]  ?? ''));
            $ip    = trim((string) ($line[5]  ?? ''));
            $sn    = trim((string) ($line[6]  ?? ''));
            $notes = trim((string) ($line[7]  ?? ''));
            $w3    = trim((string) ($line[8]  ?? ''));
            $w4    = trim((string) ($line[9]  ?? ''));
            $w5    = trim((string) ($line[10] ?? ''));

            // ---- Дата ----
            if ($date !== '') {
                try {
                    $dt = Carbon::createFromFormat('d.m.Y', $date);
                    if ($dt === false) {
                        throw new \Exception('bad date');
                    }
                    $lastDate = $dt->toDateString();
                } catch (\Throwable $e) {
                    $errors[] = "Строка {$num}: некорректная дата '{$date}' — пропущена";
                    $skipped++;
                    continue;
                }
            }

            if ($lastDate === null) {
                $errors[] = "Строка {$num}: нет даты и нет предыдущей — пропущена";
                $skipped++;
                continue;
            }

            // ---- Очистка ФИО ----
            $w1 = $this->cleanName($w1);
            $w2 = $this->cleanName($w2);
            $w3 = $this->cleanName($w3);
            $w4 = $this->cleanName($w4);
            $w5 = $this->cleanName($w5);

            if ($w1 === null) {
                $errors[] = "Строка {$num}: пустое ФИО 1 — пропущена";
                $skipped++;
                continue;
            }

            // ---- Разбивка по /,; и подстановка ----
            $splitNames = function (?string $v): array {
                if ($v === null) return [];
                return array_values(array_filter(
                    array_map(fn($s) => trim($s), preg_split('/[\/,;]+/u', $v)),
                    fn($s) => $s !== '' && $s !== '-' && $s !== '—'
                ));
            };

            $allWorkers = [];
            foreach ([$w1, $w2, $w3, $w4, $w5] as $raw) {
                foreach ($splitNames($raw) as $one) {
                    // 1. Алиас
                    $one = $this->workerAliases[$one] ?? $one;
                    // 2. Нормализация инициалов (убрать пробел между ними, добавить точку)
                    $normalized = $this->normalizeFio($one);
                    if ($normalized !== $one) {
                        $statNormalized++;
                        $one = $normalized;
                    }
                    // 3. Подстановка по фамилии, если осталась только фамилия
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

            // ---- Проверка ----
            $bad = array_values(array_filter(
                array_filter([$w1, $w2, $w3, $w4, $w5], fn($w) => $w !== null),
                fn($w) => !in_array($w, $allowedWorkers, true)
            ));
            if (!empty($bad)) {
                $errors[] = "Строка {$num}: исполнители не из справочника: " . implode(', ', array_unique($bad)) . " — пропущена";
                $skipped++;
                continue;
            }

            // ---- IP ----
            $ip = $ip === '' ? null : mb_substr($ip, 0, 64);

            // ---- Примечание ----
            $notes = $notes === ''
                ? null
                : trim(preg_replace('/\s+/u', ' ', $notes));

            // ---- S/N ----
            $snList = [];
            if ($sn === '') {
                $snList = [null];
            } else {
                foreach (preg_split('/\r?\n/', $sn) as $part) {
                    $part = trim($part);
                    if ($part !== '') {
                        $snList[] = $part;
                    }
                }
                if (empty($snList)) {
                    $snList = [null];
                }
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
        }

        fclose($fh);

        $this->newLine();
        $this->info('=== Итог ===');
        $this->line("Создано записей: {$created}");
        $this->line("Пропущено строк: {$skipped}");
        $this->line("Подставлено по фамилии: {$statSurname}");
        $this->line("Нормализовано инициалов: {$statNormalized}");

        if (!empty($errors)) {
            $errPath = '/tmp/manet_errors.csv';
            $fhErr = fopen($errPath, 'w');
            fputcsv($fhErr, ['message'], ';');
            foreach ($errors as $e) {
                fputcsv($fhErr, [$e], ';');
            }
            fclose($fhErr);

            $this->newLine();
            $this->warn('=== Предупреждения (первые 20 из ' . count($errors) . ') ===');
            foreach (array_slice($errors, 0, 20) as $e) {
                $this->line("  • {$e}");
            }
            if (count($errors) > 20) {
                $this->line('  … и ещё ' . (count($errors) - 20));
            }
            $this->warn("Полный список: {$errPath}");
        }

        if ($dry) {
            $this->newLine();
            $this->warn('=== DRY: изменения НЕ сохранены ===');
        }

        return self::SUCCESS;
    }

    private function cleanName(string $v): ?string
    {
        $v = trim($v);
        if ($v === '' || $v === '-' || $v === '—') {
            return null;
        }
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
        return trim($v);
    }

    /**
     * Нормализация ФИО:
     * - "Петров М. И." → "Петров М.И."
     * - "Смирнов А.В"  → "Смирнов А.В."
     * - лишние пробелы схлопываются
     */
    private function normalizeFio(?string $v): ?string
    {
        if ($v === null) return null;
        $v = preg_replace('/\s+/u', ' ', trim($v)) ?? $v;
        // Убрать пробел между инициалами: "М. И." → "М.И."
        $v = preg_replace('/([А-ЯЁ])\.\s+([А-ЯЁ])\./u', '$1.$2.', $v) ?? $v;
        // Добавить точку, если её нет: "Смирнов А.В" → "Смирнов А.В."
        if (preg_match('/^(.+?)\s+([А-ЯЁ])\.([А-ЯЁ])$/u', $v, $m)) {
            $v = $m[1] . ' ' . $m[2] . '.' . $m[3] . '.';
        }
        return $v;
    }
}