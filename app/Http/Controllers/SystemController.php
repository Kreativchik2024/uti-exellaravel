<?php

namespace App\Http\Controllers;

use App\Console\Commands\SystemTask;
use App\Console\Commands\VerifyBackup;
use App\Models\ActiveWorkerSession;
use App\Models\AuditLog;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * «Состояние сервера» для администратора: место на диске, резервные копии,
 * планировщик, логи, база, настройки. Каждая проверка — ok / warn / bad.
 * У проверок есть кнопки действий: run (фоновая задача SystemTask), link, post, copy (скопировать текст).
 */
class SystemController extends Controller
{
    public function index()
    {
        $checks = [];
        $add = function (string $group, string $status, string $title, string $detail = '', array $actions = []) use (&$checks) {
            $checks[$group][] = compact('status', 'title', 'detail', 'actions');
        };
        $run = fn (string $task, string $label, ?string $confirm = null) => ['type' => 'run', 'task' => $task, 'label' => $label, 'confirm' => $confirm];
        $copy = fn (string $text, string $label = 'Скопировать команду') => ['type' => 'copy', 'text' => $text, 'label' => $label];
        $external = in_array('backup_external', (array) config('backup.backup.destination.disks'), true);

        // ---------------------------------------------------------------- диск
        foreach (array_filter(['Сервер (сайт и копии)' => base_path(), 'Второй носитель копий' => $external ? config('filesystems.disks.backup_external.root') : null]) as $label => $path) {
            $free = @disk_free_space($path);
            $total = @disk_total_space($path);
            if (! $free || ! $total) {
                $add('Диск', 'bad', $label, "Недоступен: {$path}");
                continue;
            }
            $pct = $free / $total * 100;
            $add('Диск', $pct < 10 ? 'bad' : ($pct < 20 ? 'warn' : 'ok'), $label,
                'Свободно ' . VerifyBackup::size($free) . ' из ' . VerifyBackup::size($total) . ' (' . round($pct) . '%)');
        }

        // ---------------------------------------------------------------- резервные копии
        $name = config('backup.backup.name');
        foreach (config('backup.backup.destination.disks', []) as $diskName) {
            $disk = Storage::disk($diskName);
            $files = collect(@$disk->files($name) ?: [])->filter(fn ($f) => str_ends_with($f, '.zip'));
            if ($files->isEmpty()) {
                $add('Резервные копии', 'bad', "Копии на «{$diskName}»", 'Ни одной копии',
                    [$run('backup-db', 'Сделать копию базы'), $run('backup-full', 'Сделать полную копию')]);
                continue;
            }
            $sorted = $files->sortByDesc(fn ($f) => $disk->lastModified($f))->values();
            $latest = $sorted->first();
            $at = Carbon::createFromTimestamp($disk->lastModified($latest))->setTimezone(config('app.timezone'));
            $hours = (int) $at->diffInHours(now());
            $total = $sorted->sum(fn ($f) => $disk->size($f));
            $full = $sorted->first(fn ($f) => VerifyBackup::hasFiles($disk->path($f)));
            // Сервер работает 08:00–20:00: днём копия должна быть не старше 2 ч, ночной перерыв — норма
            $workTime = now()->between(now()->copy()->setTime(8, 30), now()->copy()->setTime(20, 0));
            $status = $hours > VerifyBackup::FRESH_HOURS ? 'bad' : ($workTime && $hours > 2 ? 'warn' : 'ok');
            $add('Резервные копии', $status, "Копии на «{$diskName}»",
                "Последняя {$at->format('d.m.Y H:i')} (" . VerifyBackup::size($disk->size($latest)) . "), всего {$files->count()} за неделю, занимают " . VerifyBackup::size($total)
                . ($full ? '. Последняя полная: ' . Carbon::createFromTimestamp($disk->lastModified($full))->setTimezone(config('app.timezone'))->format('d.m.Y H:i') : '. Полной копии ещё нет (делается в 19:50)'),
                array_filter([
                    $run('backup-db', 'Сделать копию базы'),
                    $run('backup-full', 'Сделать полную копию'),
                    $diskName === 'local' && $full ? ['type' => 'link', 'url' => route('system.backup.download'), 'label' => 'Скачать полную копию'] : null,
                    $run('clean', 'Удалить старше 7 дней'),
                ]));
        }
        if (! $external) {
            $add('Резервные копии', 'warn', 'Второй носитель', 'Копии только на SD-карте сервера. Подключите флешку, укажите путь в .env и обновите кеш. Или скачивайте полную копию кнопкой выше на другой компьютер.',
                [$copy('BACKUP_EXTERNAL_PATH=/mnt/backup', 'Скопировать строку для .env'),
                 $copy('sudo mkdir -p /mnt/backup && sudo mount /dev/sda1 /mnt/backup && sudo chown -R www-data:www-data /mnt/backup', 'Скопировать команду подключения флешки')]);
        }

        $verify = Cache::get(VerifyBackup::CACHE_KEY);
        if (! $verify) {
            $add('Резервные копии', 'warn', 'Проверка копии', 'Ещё не выполнялась (каждый день в 08:15 проверяется вчерашняя полная копия)',
                [$run('verify', 'Проверить сейчас')]);
        } else {
            $at = Carbon::parse($verify['at'])->setTimezone(config('app.timezone'));
            $add('Резервные копии', $verify['ok'] ? ($at->lt(now()->subHours(36)) ? 'warn' : 'ok') : 'bad',
                'Проверка копии' . ($verify['restore'] ? ' (с пробным восстановлением)' : ''),
                $at->format('d.m.Y H:i') . ' — ' . ($verify['ok'] ? 'в порядке. ' . implode('. ', array_slice($verify['notes'], -2)) : implode('. ', $verify['problems'])),
                [$run('verify', 'Проверить сейчас')]);
        }
        if (! config('backup.verify_database')) {
            $add('Резервные копии', 'warn', 'Пробное восстановление', 'Не настроено: создайте пустую базу и укажите её в BACKUP_VERIFY_DATABASE (.env), затем обновите кеш',
                [$copy('sudo mysql -e "CREATE DATABASE uti_verify CHARACTER SET utf8mb4; GRANT ALL ON uti_verify.* TO \'' . config('database.connections.mysql.username') . '\'@\'localhost\';"', 'Скопировать команду создания базы'),
                 $copy('BACKUP_VERIFY_DATABASE=uti_verify', 'Скопировать строку для .env')]);
        } else {
            $add('Резервные копии', 'ok', 'Пробное восстановление', 'Каждый день в 08:45 в базу «' . config('backup.verify_database') . '»', [$run('restore', 'Восстановить пробно сейчас')]);
        }

        // ---------------------------------------------------------------- планировщик
        $beat = Cache::get('scheduler.heartbeat');
        $beatAge = $beat ? (int) Carbon::parse($beat)->diffInMinutes(now()) : null;
        $cron = '* * * * * cd ' . base_path() . ' && php artisan schedule:run >> /dev/null 2>&1';
        $add('Планировщик', $beatAge !== null && $beatAge <= 5 ? 'ok' : 'bad', 'Плановые задачи (бэкапы, очистка чата и журнала)',
            $beatAge === null ? 'Не запускался: нет строки cron. Выполните «sudo crontab -u www-data -e» и добавьте строку' : "Последний запуск {$beatAge} мин назад",
            $beatAge !== null && $beatAge <= 5 ? [] : [$copy('sudo crontab -u www-data -e', 'Скопировать команду'), $copy($cron, 'Скопировать строку cron')]);

        // ---------------------------------------------------------------- логи
        $logs = collect(glob(storage_path('logs/*.log')) ?: []);
        $logSize = $logs->sum(fn ($f) => filesize($f));
        $add('Логи', $logSize > 200 * 1024 * 1024 ? 'warn' : 'ok', 'Размер логов', VerifyBackup::size($logSize) . ', файлов: ' . $logs->count(),
            $logs->isEmpty() ? [] : [
                ['type' => 'link', 'url' => route('system.logs.download'), 'label' => 'Скачать свежий лог'],
                ['type' => 'post', 'url' => route('system.logs.clear'), 'label' => 'Очистить логи', 'confirm' => 'Очистить все логи? Ошибки за прошлые дни пропадут.'],
            ]);
        if (config('logging.channels.stack.channels') === ['single'] || in_array('single', (array) config('logging.channels.stack.channels'), true)) {
            $add('Логи', 'warn', 'Лог одним файлом', 'Файл растёт бесконечно. Добавьте строки в .env и обновите кеш (новый файл каждый день, хранится 14 дней)',
                [$copy("LOG_STACK=daily\nLOG_DAILY_DAYS=14", 'Скопировать строки для .env'), $run('cache', 'Обновить кеш')]);
        }
        $errors = $this->recentErrors($logs);
        $add('Логи', $errors ? 'warn' : 'ok', 'Ошибки за сутки', $errors ? count($errors) . ' шт. Последняя: ' . end($errors) : 'Нет');

        // ---------------------------------------------------------------- база и файлы
        try {
            $db = DB::connection()->getDatabaseName();
            $size = DB::selectOne('SELECT SUM(data_length + index_length) AS s FROM information_schema.tables WHERE table_schema = ?', [$db])->s ?? 0;
            $add('Данные', 'ok', 'База данных', "«{$db}»: " . VerifyBackup::size((int) $size));
        } catch (\Throwable) {
            // не MySQL (тесты) — пропускаем
        }
        foreach (['chat' => 'Файлы чата (7 дней)', 'news' => 'Картинки новостей', 'firmware' => 'Прошивки'] as $dir => $label) {
            $add('Данные', 'ok', $label, VerifyBackup::size($this->dirSize(storage_path("app/private/{$dir}"))),
                $dir === 'chat' ? [$run('chat-prune', 'Очистить старше 7 дней')] : []);
        }

        // ---------------------------------------------------------------- настройки
        $add('Настройки', config('app.debug') ? 'bad' : 'ok', 'Режим отладки', config('app.debug') ? 'Включён: посетители видят пароли и пути при ошибке. APP_DEBUG=false' : 'Выключен');
        if (request()->isSecure() && ! config('session.secure')) {
            $add('Настройки', 'warn', 'Cookie входа', 'Сайт на HTTPS, но SESSION_SECURE_COOKIE=false');
        }
        $upload = min($this->iniBytes(ini_get('upload_max_filesize')), $this->iniBytes(ini_get('post_max_size')));
        $add('Настройки', $upload < 25 * 1024 * 1024 ? 'warn' : 'ok', 'Размер загрузки в PHP',
            VerifyBackup::size($upload) . ($upload < 25 * 1024 * 1024 ? ' — файлы в чат до 20 МБ не пройдут: upload_max_filesize и post_max_size ≥ 25M' : ''));
        if (str_contains((string) config('legal.operator'), '[')) {
            $add('Настройки', 'warn', 'Правила пользования', 'Не указана организация. Впишите свои данные в строки, добавьте в .env и обновите кеш',
                [$copy("LEGAL_OPERATOR=\"ООО «Название»\"\nLEGAL_ADDRESS=\"г. Город, ул. Улица, д. 1\"\nLEGAL_CONTACT=\"администратор системы\"", 'Скопировать строки для .env'),
                 ['type' => 'link', 'url' => route('legal.rules'), 'label' => 'Открыть правила']]);
        }
        $add('Настройки', 'ok', 'Версии', 'PHP ' . PHP_VERSION . ', Laravel ' . app()->version(),
            [$run('cache', 'Обновить кеш сайта', 'Сбросить и пересобрать кеш? Нужно после правки .env или выкладки новой версии.')]);

        // ---------------------------------------------------------------- сейчас
        $online = ActiveWorkerSession::where('last_heartbeat_at', '>=', now()->subMinutes(3))->distinct('employee_id')->count('employee_id');

        return view('system.index', [
            'checks'     => $checks,
            'online'     => $online,
            'serverTime' => now(),
            'task'       => Cache::get(SystemTask::CACHE_KEY),
        ]);
    }

    // ================================================================ действия

    /** Запуск задачи обслуживания в фоне (страница не ждёт — копия может делаться минуту). */
    public function run(Request $r, string $task)
    {
        abort_unless(isset(SystemTask::TASKS[$task]), 404);
        $current = Cache::get(SystemTask::CACHE_KEY);
        if (($current['status'] ?? null) === 'running' && Carbon::parse($current['started_at'])->gt(now()->subMinutes(30))) {
            return back()->withErrors(['task' => "Уже выполняется: {$current['label']}. Дождитесь окончания."]);
        }

        $label = SystemTask::TASKS[$task][0];
        Cache::forever(SystemTask::CACHE_KEY, ['task' => $task, 'label' => $label, 'status' => 'running', 'started_at' => now()->toIso8601String(), 'finished_at' => null, 'output' => '']);
        AuditLog::write('system', 'run', "Запуск: {$label}", ['section_id' => null]);

        if (app()->runningUnitTests()) {
            Artisan::call('system:task', ['task' => $task]);
        } else {
            $this->spawn($task);
        }

        return back()->with('ok', "Запущено: {$label}. Результат появится на этой странице.");
    }

    /** Ход фоновой задачи (страница опрашивает каждые 3 с). */
    public function status()
    {
        return response()->json(Cache::get(SystemTask::CACHE_KEY))->header('Cache-Control', 'no-store');
    }

    /** Последняя полная копия — на другой компьютер (второй носитель вручную). */
    public function downloadBackup()
    {
        $disk = Storage::disk('local');
        $full = collect($disk->files(config('backup.backup.name')))
            ->filter(fn ($f) => str_ends_with($f, '.zip'))
            ->sortByDesc(fn ($f) => $disk->lastModified($f))
            ->first(fn ($f) => VerifyBackup::hasFiles($disk->path($f)));
        abort_unless($full, 404, 'Полной копии нет');
        AuditLog::write('system', 'download', 'Скачал полную копию ' . basename($full), ['section_id' => null]);

        return response()->download($disk->path($full), basename($full));
    }

    public function downloadLog()
    {
        $latest = collect(glob(storage_path('logs/*.log')) ?: [])->sortByDesc(fn ($f) => filemtime($f))->first();
        abort_unless($latest, 404);

        return response()->download($latest, basename($latest));
    }

    public function clearLogs()
    {
        $n = 0;
        foreach (glob(storage_path('logs/*.log')) ?: [] as $f) {
            if (@file_put_contents($f, '') !== false) $n++;
        }
        AuditLog::write('system', 'deleted', "Очищены логи ({$n} файл.)", ['section_id' => null]);

        return back()->with('ok', "Логи очищены: {$n} файл.");
    }

    /** Отдельный процесс php artisan system:task — продолжит работу после ответа браузеру. */
    private function spawn(string $task): void
    {
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        $artisan = base_path('artisan');

        if (PHP_OS_FAMILY === 'Windows') {
            pclose(popen('start /B "" ' . escapeshellarg($php) . ' ' . escapeshellarg($artisan) . ' system:task ' . escapeshellarg($task) . ' > NUL 2>&1', 'r'));
        } else {
            exec(escapeshellarg($php) . ' ' . escapeshellarg($artisan) . ' system:task ' . escapeshellarg($task) . ' > /dev/null 2>&1 &');
        }
    }

    /** Строки ERROR за последние сутки из свежих логов (читается только конец файлов). */
    private function recentErrors($logs): array
    {
        $since = now()->subDay();
        $out = [];
        foreach ($logs->sortByDesc(fn ($f) => filemtime($f))->take(2) as $file) {
            $fh = fopen($file, 'rb');
            fseek($fh, max(0, filesize($file) - 2 * 1024 * 1024));
            while (($line = fgets($fh)) !== false) {
                if (preg_match('/^\[(\d{4}-\d\d-\d\d \d\d:\d\d:\d\d)\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.{0,140})/u', $line, $m)
                    && Carbon::parse($m[1])->gte($since)) {
                    $out[] = "{$m[1]} — {$m[3]}";
                }
            }
            fclose($fh);
        }
        sort($out);

        return $out;
    }

    private function dirSize(string $dir): int
    {
        if (! is_dir($dir)) return 0;
        $size = 0;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) {
            $size += $f->getSize();
        }

        return $size;
    }

    private function iniBytes(string|false $v): int
    {
        $v = trim((string) $v);
        $n = (int) $v;

        return match (strtolower(substr($v, -1))) {
            'g' => $n * 1024 ** 3, 'm' => $n * 1024 ** 2, 'k' => $n * 1024, default => $n,
        };
    }
}
