<?php

namespace App\Console\Commands;

use App\Models\Employee;
use App\Models\MotorRegulatorRecord;
use Carbon\Carbon;
use Illuminate\Console\Command;

class ImportMotorRegulator extends Command
{
    protected $signature = 'import:motor-regulator
                            {file : Путь к CSV-файлу}
                            {--dry : Проверить без записи в БД}
                            {--user=1 : ID пользователя для created_by}
                            {--skip-existing : Пропускать записи, которые уже есть (record_date+worker1+qty+notes)}';

    protected $description = 'Импорт регулятора мотора из CSV с защитой от дубликатов';

    private array $workerAliases = [
        // 'Карасев А.А.' => 'Карасёв А.А.',
    ];

    public function handle(): int
    {
        $path = $this->argument('file');
        $dry  = (bool) $this->option('dry');
        $userId = (int) $this->option('user') ?: null;
        $skipExisting = (bool) $this->option('skip-existing');

        if (!file_exists($path)) {
            $this->error("Файл не найден: {$path}");
            return self::FAILURE;
        }

        $raw = file_get_contents($path);
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = mb_convert_encoding($raw, 'UTF-8', 'Windows-1251');
            $this->warn('Кодировка сконвертирована Windows-1251 → UTF-8');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $raw);
        rewind($stream);

        $allowedWorkers = Employee::query()->active()->pluck('short_name')->all();

        $row = 0;
        $created = 0;
        $skippedExisting = 0;
        $skippedError = 0;
        $errors = [];
        $lastDate = null;

        if ($dry) {
            $this->warn('=== РЕЖИМ DRY: изменения НЕ сохраняются ===');
        }
        $this->line('Активных сотрудников в справочнике: ' . count($allowedWorkers));
        $this->line('Защита от дубликатов: ' . ($skipExisting ? 'включена' : 'выключена'));

        while (($line = fgetcsv($stream, 0, ';')) !== false) {
            $row++;
            if ($row === 1) {
                continue;
            }
            if (trim(implode('', $line)) === '') {
                continue;
            }

            $date  = trim((string) ($line[0] ?? ''));
            $qty   = trim((string) ($line[1] ?? ''));
            $w1    = trim((string) ($line[2] ?? ''));
            $w2    = trim((string) ($line[3] ?? ''));
            $notes = trim((string) ($line[4] ?? ''));
            $w3    = trim((string) ($line[5] ?? ''));
            $w4    = trim((string) ($line[6] ?? ''));
            $w5    = trim((string) ($line[7] ?? ''));

            if ($date !== '') {
                try {
                    $dt = Carbon::createFromFormat('d.m.Y', $date);
                    if ($dt === false) throw new \Exception('bad date');
                    $lastDate = $dt->toDateString();
                } catch (\Throwable $e) {
                    $errors[] = "Строка {$row}: некорректная дата '{$date}'";
                    $skippedError++;
                    continue;
                }
            }
            if ($lastDate === null) {
                $errors[] = "Строка {$row}: нет даты и нет предыдущей";
                $skippedError++;
                continue;
            }

            $qtyInt = (int) $qty;
            if ($qtyInt < 1) {
                $errors[] = "Строка {$row}: qty='{$qty}' (должно быть >= 1)";
                $skippedError++;
                continue;
            }

            $clean = fn(?string $v) => ($v === null || $v === '' || $v === '-' || $v === '—')
                ? null
                : trim(preg_replace('/\s+/u', ' ', $v));
            $w1 = $clean($w1); $w2 = $clean($w2); $w3 = $clean($w3); $w4 = $clean($w4); $w5 = $clean($w5);

            if ($w1 === null) {
                $errors[] = "Строка {$row}: пустое ФИО 1";
                $skippedError++;
                continue;
            }

            $applyAlias = fn(?string $v) => $v === null ? null : ($this->workerAliases[$v] ?? $v);
            $w1 = $applyAlias($w1); $w2 = $applyAlias($w2); $w3 = $applyAlias($w3); $w4 = $applyAlias($w4); $w5 = $applyAlias($w5);

            $bad = array_filter(
                [$w1, $w2, $w3, $w4, $w5],
                fn($w) => $w !== null && !in_array($w, $allowedWorkers, true)
            );
            if (!empty($bad)) {
                $errors[] = "Строка {$row}: не из справочника: " . implode(', ', array_unique($bad));
                $skippedError++;
                continue;
            }

            $notes = $notes === '' ? null : trim(preg_replace('/\s+/u', ' ', $notes));

            if ($skipExisting) {
                $exists = MotorRegulatorRecord::query()
                    ->where('record_date', $lastDate)
                    ->where('worker1', $w1)
                    ->where('qty', $qtyInt)
                    ->where(function ($q) use ($notes) {
                        if ($notes === null) {
                            $q->whereNull('notes');
                        } else {
                            $q->where('notes', $notes);
                        }
                    })
                    ->exists();

                if ($exists) {
                    $skippedExisting++;
                    continue;
                }
            }

            $payload = [
                'record_date' => $lastDate,
                'qty'         => $qtyInt,
                'worker1'     => $w1,
                'worker2'     => $w2,
                'worker3'     => $w3,
                'worker4'     => $w4,
                'worker5'     => $w5,
                'notes'       => $notes,
                'created_by'  => $userId,
            ];

            if ($dry) {
                $this->line(sprintf('[DRY] %s | qty=%d | %s%s | notes=%s',
                    $lastDate, $qtyInt, $w1, $w2 ? " + {$w2}" : '', $notes ?? '—'));
                $created++;
                continue;
            }

            try {
                MotorRegulatorRecord::create($payload);
                $created++;
            } catch (\Throwable $e) {
                $errors[] = "Строка {$row}: " . $e->getMessage();
                $skippedError++;
            }
        }

        fclose($stream);

        $this->newLine();
        $this->info('=== Итог ===');
        $this->line("Создано записей: {$created}");
        $this->line("Пропущено (уже есть): {$skippedExisting}");
        $this->line("Пропущено (ошибки): {$skippedError}");

        if (!empty($errors)) {
            $this->newLine();
            $this->warn('=== Ошибки (первые 20 из ' . count($errors) . ') ===');
            foreach (array_slice($errors, 0, 20) as $e) {
                $this->line("  • {$e}");
            }
        }

        if ($dry) {
            $this->newLine();
            $this->warn('=== DRY: изменения НЕ сохранены ===');
        }

        return self::SUCCESS;
    }
}
