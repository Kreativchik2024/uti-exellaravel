cat > /var/www/uti-exellaravel/app/Console/Commands/ImportCommModule.php << 'PHPEOF'
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
                            {--user=1 : ID пользователя для created_by}
                            {--allow-unknown : Не пропускать строки с неизвестными исполнителями}
                            {--placeholder= : Текст для пустого worker1 (например, «—»)}';

    protected $description = 'Импорт модулей связи из CSV (manet.csv) в comm_module_records';

    private array $workerAliases = [
        'Трушкин Ф.В.' => 'Трушкин Ф.Д.',
        'Карасев А.А.' => 'Карасёв А.А.',
    ];

    public function handle(): int
    {
        $path = $this->argument('file');
        $dry  = (bool) $this->option('dry');
        $userId = (int) $this->option('user') ?: null;
        $allowUnknown = (bool) $this->option('allow-unknown');
        $placeholder  = (string) $this->option('placeholder');

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
                $surnameMap[$s] = null;
            }
        }

        $row = 0;
        $lastDate = null;
        $created = 0;
        $skipped = 0;
        $errors = [];
        $statSurname = 0;
        $statNormalized = 0;
        $statAlias = 0;
        $statPlaceholder = 0;

        if ($dry) {
            $this->warn('=== РЕЖИМ DRY: изменения НЕ сохраняются ===');
        }
        $this->line('Активных сотрудников в справочнике: ' . count($allowedWorkers));
        $this->line('Алиасов задано: ' . count($this->workerAliases));
        if ($allowUnknown) {
            $this->warn('--allow-unknown: неизвестные исполнители будут записаны как есть');
        }
        if ($placeholder !== '') {
            $this->line("--placeholder: пустой worker1 будет заменён на '{$placeholder}'");
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

            $w1 = $this->cleanName($w1);
            $w2 = $this->cleanName($w2);
            $w3 = $this->cleanName($w3);
            $w4 = $this->cleanName($w4);
            $w5 = $this->cleanName($w5);

            if ($w1 === null) {
                if ($placeholder !== '') {
                    $w1 = $placeholder;
                    $statPlaceholder++;
                } else {
                    $errors[] = "Строка {$num}: пустое ФИО 1 — пропущена (используйте --placeholder=—, чтобы подставить заглушку)";
                    $skipped++;
                    continue;
                }
            }

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

            $bad = array_values(array_filter(
                array_filter([$w1, $w2, $w3, $w4, $w5], fn($w) => $w !== null),
                fn($w) => !in_array($w, $allowedWorkers, true)
            ));
            if (!empty($bad) && !$allowUnknown) {
                $errors[] = "Строка {$num}: исполнители не из справочника: " . implode(', ', array_unique($bad)) . " — пропущена (используйте --allow-unknown, чтобы записать как есть)";
                $skipped++;
                continue;
            }

            $ip = $ip === '' ? null : mb_substr($ip, 0, 64);

            $notes = $notes === ''
                ? null
                : trim(preg_replace('/\s+/u', ' ', $notes));

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
        $this->line("Применено алиасов: {$statAlias}");
        $this->line("Подставлено по фамилии: {$statSurname}");
        $this->line("Нормализовано инициалов: {$statNormalized}");
        $this->line("Заменено на заглушку: {$statPlaceholder}");

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
PHPEOF