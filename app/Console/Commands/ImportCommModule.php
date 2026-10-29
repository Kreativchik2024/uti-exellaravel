<?php

namespace App\Console\Commands;

use App\Models\CommModuleRecord;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ImportCommModule extends Command
{
    protected $signature = 'import:comm-module
                            {file : Путь к CSV-файлу}
                            {--dry : Проверить без записи в БД}
                            {--user=1 : ID пользователя для created_by}';

    protected $description = 'Импорт модулей связи из CSV (manet.csv) в comm_module_records';

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

        $row = 0;
        $lastDate = null;
        $created = 0;
        $skipped = 0;
        $errors = [];
        $totalSkippedNoSn = 0;

        if ($dry) {
            $this->warn('=== РЕЖИМ DRY: изменения НЕ сохраняются ===');
        }

        while (($line = fgetcsv($fh, 0, ';')) !== false) {
            $row++;

            // Пропускаем заголовок (и убираем BOM из первого поля)
            if ($row === 1) {
                continue;
            }

            // Пропускаем пустые строки
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

            // Дедупликация: worker1..5 не должны повторяться
            $workers = [$w1];
            foreach ([$w2, $w3, $w4, $w5] as $w) {
                if ($w !== null && !in_array($w, $workers, true)) {
                    $workers[] = $w;
                }
            }
            $w1 = $workers[0] ?? null;
            $w2 = $workers[1] ?? null;
            $w3 = $workers[2] ?? null;
            $w4 = $workers[3] ?? null;
            $w5 = $workers[4] ?? null;

            // ---- IP ----
            $ip = $ip === '' ? null : mb_substr($ip, 0, 64);

            // ---- Примечание: убираем переносы, схлопываем пробелы ----
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
            $this->newLine();
            $this->warn('=== Предупреждения (первые 20) ===');
            foreach (array_slice($errors, 0, 20) as $e) {
                $this->line("  • {$e}");
            }
            if (count($errors) > 20) {
                $this->line('  … и ещё ' . (count($errors) - 20));
            }
        }

        if ($dry) {
            $this->newLine();
            $this->warn('=== DRY: изменения НЕ сохранены ===');
        }

        return self::SUCCESS;
    }

    /**
     * Очистка ФИО: trim, `-` → null, удаление переносов.
     */
    private function cleanName(string $v): ?string
    {
        $v = trim($v);
        if ($v === '' || $v === '-' || $v === '—') {
            return null;
        }
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
        return trim($v);
    }
}