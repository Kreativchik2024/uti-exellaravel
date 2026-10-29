<?php

namespace App\Console\Commands;

use App\Models\Employee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BackfillRecordEmployees extends Command
{
    protected $signature = 'employees:backfill
                            {--fresh : Удалить все существующие связи перед бэкфилом}
                            {--chunk=200 : Размер порции записей}
                            {--dry : Только показать, что будет сделано}';

    protected $description = 'Сопоставить старые worker1..5 в записях с сотрудниками и создать pivot-связи';

    private const WORKER_FIELDS = ['worker1', 'worker2', 'worker3', 'worker4', 'worker5'];

    private array $employeeIndex = [];
    private int $recordsProcessed = 0;
    private int $linksCreated = 0;
    private int $employeesCreated = 0;

    public function handle(): int
    {
        $fresh = (bool) $this->option('fresh');
        $chunk = (int) $this->option('chunk');
        $dry   = (bool) $this->option('dry');

        if ($dry) $this->warn('=== Режим DRY: изменения не сохраняются ===');

        if ($fresh) {
            if ($dry) {
                $this->line('DRY: были бы удалены все связи из record_employee');
            } else {
                DB::table('record_employee')->truncate();
                $this->info('Все существующие связи удалены (--fresh).');
            }
        }

        $this->loadEmployeeIndex();

        foreach (config('sheets.map', []) as $slug => $cfg) {
            if (!isset($cfg['model']) || !class_exists($cfg['model'])) continue;

            $model = $cfg['model'];
            $table = (new $model)->getTable();

            $workerFields = array_values(array_filter(
                self::WORKER_FIELDS,
                fn($f) => Schema::hasColumn($table, $f)
            ));

            if (empty($workerFields)) {
                $this->line("— {$slug}: полей worker1..5 нет, пропуск");
                continue;
            }

            $total = $model::query()->count();
            $this->info("{$slug}: {$total} записей");

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            $model::query()
                ->select(array_merge(['id'], $workerFields))
                ->orderBy('id')
                ->chunk($chunk, function ($records) use ($slug, $workerFields, $dry, $bar) {
                    foreach ($records as $record) {
                        $this->processRecord($slug, $record, $workerFields, $dry);
                        $bar->advance();
                    }
                });

            $bar->finish();
            $this->newLine();
        }

        $this->newLine();
        $this->info('=== Итог ===');
        $this->line("Записей обработано: {$this->recordsProcessed}");
        $this->line("Связей создано:    {$this->linksCreated}");
        $this->line("Сотрудников создано (архивных): {$this->employeesCreated}");
        if ($dry) $this->warn('=== Режим DRY: изменения НЕ сохранены ===');

        return self::SUCCESS;
    }

    private function processRecord(string $slug, $record, array $workerFields, bool $dry): void
    {
        $this->recordsProcessed++;
        $entries = [];

        foreach ($workerFields as $field) {
            $position = (int) substr($field, 6);
            $value = trim((string) ($record->$field ?? ''));
            if ($value === '') continue;

            foreach ($this->parseWorkers($value) as $part) {
                $key = $this->normalizeName($part);
                if ($key === '' || isset($entries[$key])) continue;
                $entries[$key] = ['name' => $part, 'position' => $position];
            }
        }

        if (empty($entries)) return;

        $employeeIds = [];
        foreach ($entries as $key => $entry) {
            $employeeId = $this->resolveEmployeeId($key, $entry['name'], $dry);
            if ($employeeId !== null) {
                $employeeIds[] = ['employee_id' => $employeeId, 'position' => $entry['position']];
            }
        }

        if (empty($employeeIds)) return;

        if ($dry) {
            $this->linksCreated += count($employeeIds);
            return;
        }

        $now = now();
        $rows = array_map(fn($item) => [
            'record_type' => $slug,
            'record_id'   => $record->id,
            'employee_id' => $item['employee_id'],
            'position'    => $item['position'],
            'created_at'  => $now,
            'updated_at'  => $now,
        ], $employeeIds);

        DB::table('record_employee')->insertOrIgnore($rows);
        $this->linksCreated += count($rows);
    }

    private function resolveEmployeeId(string $key, string $originalName, bool $dry): ?int
    {
        if (isset($this->employeeIndex[$key])) {
            return $this->employeeIndex[$key];
        }

        [$last, $first] = $this->splitShortName($originalName);
        if ($last !== '') {
            $found = Employee::query()
                ->where('last_name', $last)
                ->when($first !== '', fn($q) => $q->where('first_name', 'like', $first . '%'))
                ->first();
            if ($found) {
                $this->employeeIndex[$key] = $found->id;
                return $found->id;
            }
        }

        if ($dry) {
            $this->employeesCreated++;
            return -1;
        }

        $employee = Employee::create([
            'last_name'   => $last !== '' ? $last : $originalName,
            'first_name'  => $first !== '' ? $first : '—',
            'middle_name' => null,
            'short_name'  => $originalName,
            'position'    => null,
            'department'  => null,
            'is_active'   => false,
        ]);

        $this->employeesCreated++;
        $this->employeeIndex[$key] = $employee->id;

        $this->newLine();
        $this->warn("  + Архивный сотрудник: «{$originalName}» (id={$employee->id})");

        return $employee->id;
    }

    private function loadEmployeeIndex(): void
    {
        $this->employeeIndex = [];
        Employee::query()
            ->select(['id', 'short_name'])
            ->chunkById(500, function ($employees) {
                foreach ($employees as $e) {
                    $key = $this->normalizeName((string) $e->short_name);
                    if ($key !== '' && !isset($this->employeeIndex[$key])) {
                        $this->employeeIndex[$key] = $e->id;
                    }
                }
            });
        $this->info('Загружено сотрудников: ' . count($this->employeeIndex));
    }

    private function splitShortName(string $short): array
    {
        $parts = preg_split('/\s+/u', trim($short)) ?: [];
        if (empty($parts)) return ['', ''];
        $last = array_shift($parts);
        $rest = '';
        foreach ($parts as $p) {
            $p = rtrim($p, '.');
            if ($p !== '') { $rest = $p; break; }
        }
        return [$last, $rest];
    }

    private function parseWorkers(string $value): array
    {
        $parts = preg_split('/[,;\/\n\r]+|\s+и\s+/u', $value) ?: [];
        $parts = array_map('trim', $parts);
        $parts = array_filter($parts, fn($p) => $p !== '');
        return array_values($parts);
    }

    private function normalizeName(string $name): string
    {
        $name = str_replace(["\xC2\xA0", "\t", "\n", "\r"], ' ', $name);
        $name = trim($name);
        $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        $name = str_replace('ё', 'е', $name);
        $name = preg_replace('/\s+/u', ' ', $name) ?? '';
        $name = rtrim($name, '.,;:');
        return trim($name);
    }
}