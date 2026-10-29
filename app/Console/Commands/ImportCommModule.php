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
     * Алиасы: CSV-значение => short_name в справочнике.
     * Раскомментируйте и подставьте, когда узнаете полное ФИО.
     */
    private array $workerAliases = [
        // 'Мешков' => 'Мешков А.А.',
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

        $row = 0;
        $lastDate = null;
        $created = 0;
        $skipped = 0;
        $errors = [];

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

            // ---- Дата: наследуем от предыдущей непустой ----
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

            // ---- Разбивка комбинированных ФИО: "Иванов И.И./Петров П.П." или "Иванов И.И, Петров П.П." ----
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
                    $one = $this->workerAliases[$one] ?? $one;
                    $one = $this->normalizeFio($one);
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

            // ---- Проверка исполнителей по справочнику активных ----
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

            // ---- S/N: разбиваем по переносам строк ----
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
     * Нормализация точки в инициалах: "Смирнов А.В" -> "Смирнов А.В."
     */
    private function normalizeFio(?string $v): ?string
    {
        if ($v === null) return null;
        if (preg_match('/^(.+?)\s+([А-ЯЁ])\.([А-ЯЁ])$/u', $v, $m)) {
            return $m[1] . ' ' . $m[2] . '.' . $m[3] . '.';
        }
        return $v;
    }
}