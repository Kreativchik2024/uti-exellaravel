<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use ZipArchive;

/**
 * Проверка, что последняя резервная копия пригодна для восстановления.
 *
 *  1. Копия свежая — на каждом диске из config/backup.php. Сервер работает 08:00–20:00,
 *     поэтому ночной перерыв (с 19:50 до утра) нормален; проблема — если копии нет дольше FRESH_HOURS.
 *     С --full проверяется последняя ПОЛНАЯ копия (база + файлы, 19:50), а не утренняя копия базы.
 *  2. Архив открывается (с паролем, если задан) и все файлы читаются без ошибок CRC.
 *  3. Дамп базы дописан до конца и содержит все таблицы рабочей базы.
 *  4. С --restore: дамп загружается в отдельную проверочную базу (BACKUP_VERIFY_DATABASE),
 *     сверяется число строк в таблицах, после чего проверочная база очищается.
 *
 * Результат — на странице «Состояние сервера» (Cache: backup.verify).
 */
class VerifyBackup extends Command
{
    protected $signature = 'backup:verify
        {--full : Проверить последнюю полную копию (база + файлы), а не самую свежую}
        {--restore : Пробное восстановление в проверочную базу}';

    /** Самая свежая копия не старше стольких часов (ночной перерыв 19:50 → 08:05 ≈ 12 ч). */
    public const FRESH_HOURS = 14;

    protected $description = 'Проверить, что последняя резервная копия целая и восстанавливается';

    public const CACHE_KEY = 'backup.verify';

    private array $problems = [];
    private array $notes = [];

    public function handle(): int
    {
        $name = config('backup.backup.name');
        $checked = 0;

        foreach (config('backup.backup.destination.disks', []) as $diskName) {
            $disk = Storage::disk($diskName);
            $files = collect($disk->files($name))->filter(fn ($f) => str_ends_with($f, '.zip'))
                ->sortByDesc(fn ($f) => $disk->lastModified($f))->values();

            if ($files->isEmpty()) {
                $this->problem("Диск «{$diskName}»: резервных копий нет");
                continue;
            }

            $latest = $files->first();
            $age = now()->diffInHours(\Carbon\Carbon::createFromTimestamp($disk->lastModified($latest)));
            $this->note("Диск «{$diskName}»: {$files->count()} коп., последняя " . basename($latest) . ' (' . self::size($disk->size($latest)) . ')');
            if ($age > self::FRESH_HOURS) {
                $this->problem("Диск «{$diskName}»: последней копии {$age} ч — бэкапы не выполняются");
            }

            // Полную проверку содержимого делаем по одной копии — на всех дисках архивы одинаковые
            if ($checked++ === 0) {
                $target = $latest;
                if ($this->option('full')) {
                    $target = $files->first(fn ($f) => self::hasFiles($disk->path($f)));
                    if (! $target) {
                        $this->problem("Диск «{$diskName}»: полной копии (с файлами) нет — она делается в 19:50");
                        continue;
                    }
                    $this->note('Полная копия: ' . basename($target) . ' (' . self::size($disk->size($target)) . ')');
                }
                $this->checkArchive($disk->path($target));
            }
        }

        $ok = $this->problems === [];
        Cache::forever(self::CACHE_KEY, [
            'ok'       => $ok,
            'at'       => now()->toIso8601String(),
            'problems' => $this->problems,
            'notes'    => $this->notes,
            'restore'  => (bool) $this->option('restore'),
            'full'     => (bool) $this->option('full'),
        ]);

        if ($ok) {
            $this->info('Резервная копия в порядке.');
            return self::SUCCESS;
        }
        Log::error('Проверка резервной копии: ' . implode('; ', $this->problems));
        $this->error('Найдены проблемы с резервной копией.');

        return self::FAILURE;
    }

    /** В архиве есть не только дамп базы (это полная копия). */
    public static function hasFiles(string $path): bool
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) return false;
        $files = 0;
        for ($i = 0; $i < $zip->numFiles && $files < 2; $i++) {
            if (! str_ends_with(str_replace('\\', '/', $zip->getNameIndex($i)), '/')) $files++;
        }
        $zip->close();

        return $files > 1;
    }

    private function checkArchive(string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            $this->problem('Архив не открывается: ' . basename($path));
            return;
        }
        if ($password = config('backup.backup.password')) {
            $zip->setPassword($password);
        }

        // Каждый файл читается целиком: ошибка CRC или неверный пароль дадут false
        $dump = null;
        $broken = 0;
        $files = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = $zip->statIndex($i);
            $name = str_replace('\\', '/', $entry['name']);   // на Windows разделитель — обратная косая
            if (str_ends_with($name, '/')) continue;
            $files++;
            $stream = $zip->getStreamIndex($i);
            if ($stream === false) { $broken++; continue; }
            $read = 0;
            while (! feof($stream)) {
                $chunk = fread($stream, 1 << 20);
                if ($chunk === false) { $broken++; break; }
                $read += strlen($chunk);
            }
            fclose($stream);
            if ($read !== $entry['size']) $broken++;
            if (str_starts_with($name, 'db-dumps/') && str_ends_with($name, '.sql')) {
                $dump = $i;
            }
        }
        $this->note("В архиве файлов: {$files}");
        if ($broken) {
            $this->problem("Повреждённых файлов в архиве: {$broken}");
        }

        if ($dump === null) {
            $this->problem('В архиве нет дампа базы данных (db-dumps/*.sql)');
            $zip->close();
            return;
        }

        $tmp = storage_path('app/backup-temp/verify-' . getmypid() . '.sql');
        @mkdir(dirname($tmp), 0775, true);
        file_put_contents($tmp, $zip->getStreamIndex($dump));
        $zip->close();

        try {
            $this->checkDump($tmp);
            if ($this->option('restore')) {
                $this->restoreTest($tmp);
            }
        } finally {
            @unlink($tmp);
        }
    }

    private function checkDump(string $file): void
    {
        $size = filesize($file);
        $this->note('Дамп базы: ' . self::size($size));

        // Конец файла: mysqldump последней строкой восстанавливает настройки (SQL_NOTES);
        // при обрыве её нет. «Dump completed» бывает только с комментариями (их отключают).
        $fh = fopen($file, 'rb');
        fseek($fh, max(0, $size - 4096));
        $tail = stream_get_contents($fh);
        if (! str_contains($tail, 'SQL_NOTES=@OLD_SQL_NOTES') && ! str_contains($tail, 'Dump completed')) {
            $this->problem('Дамп базы оборван (нет завершающих строк mysqldump)');
        }

        // Все таблицы рабочей базы есть в дампе
        rewind($fh);
        $inDump = [];
        while (($line = fgets($fh)) !== false) {
            if (preg_match('/^CREATE TABLE `([^`]+)`/', $line, $m)) $inDump[$m[1]] = true;
        }
        fclose($fh);

        // Только таблицы рабочей базы (без схемы getTables() вернёт таблицы всех баз сервера)
        $live = collect(Schema::getTables(DB::connection()->getDatabaseName()))->pluck('name')->all();
        $missing = array_values(array_diff($live, array_keys($inDump)));
        $this->note('Таблиц в дампе: ' . count($inDump) . ' из ' . count($live));
        if ($missing) {
            $this->problem('В дампе нет таблиц: ' . implode(', ', array_slice($missing, 0, 10)));
        }
    }

    /** Загрузка дампа в отдельную базу и сверка числа строк. */
    private function restoreTest(string $file): void
    {
        $target = config('backup.verify_database');
        if (! $target) {
            $this->problem('Пробное восстановление: не задана проверочная база (BACKUP_VERIFY_DATABASE в .env)');
            return;
        }
        $main = config('database.connections.mysql');
        if ($target === $main['database']) {
            $this->problem('Проверочная база совпадает с рабочей — восстановление не выполнялось');
            return;
        }

        $bin = rtrim((string) ($main['dump']['dump_binary_path'] ?? ''), '/\\');
        $mysql = $bin !== '' ? $bin . DIRECTORY_SEPARATOR . 'mysql' : 'mysql';
        $process = new Process(
            [$mysql, '--host=' . $main['host'], '--port=' . $main['port'], '--user=' . $main['username'], $target],
            null,
            ['MYSQL_PWD' => (string) $main['password']],
            fopen($file, 'rb'),
            1800,
        );
        $process->run();
        if (! $process->isSuccessful()) {
            $this->problem('Пробное восстановление не удалось: ' . mb_strimwidth(trim($process->getErrorOutput()), 0, 300, '…'));
            return;
        }

        config(['database.connections.backup_verify' => array_merge($main, ['database' => $target])]);
        $check = DB::connection('backup_verify');
        try {
            $restored = collect($check->select('SHOW TABLES'))->map(fn ($r) => array_values((array) $r)[0]);
            $users = $check->table('users')->count();
            $this->note("Восстановление: таблиц {$restored->count()}, пользователей {$users}");
            if ($users === 0) {
                $this->problem('Восстановленная база пустая (нет пользователей)');
            }
        } finally {
            // Очищаем проверочную базу
            $check->statement('SET FOREIGN_KEY_CHECKS=0');
            foreach ($check->select('SHOW TABLES') as $row) {
                $check->statement('DROP TABLE IF EXISTS `' . array_values((array) $row)[0] . '`');
            }
            $check->statement('SET FOREIGN_KEY_CHECKS=1');
            DB::purge('backup_verify');
        }
    }

    private function problem(string $text): void
    {
        $this->problems[] = $text;
        $this->warn('✗ ' . $text);
    }

    private function note(string $text): void
    {
        $this->notes[] = $text;
        $this->line('• ' . $text);
    }

    public static function size(int|float $bytes): string
    {
        $u = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
        $i = 0;
        while ($bytes >= 1024 && $i < 4) { $bytes /= 1024; $i++; }

        return round($bytes, $i ? 1 : 0) . ' ' . $u[$i];
    }
}
