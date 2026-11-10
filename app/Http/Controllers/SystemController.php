<?php

namespace App\Http\Controllers;

use App\Console\Commands\VerifyBackup;
use App\Models\ActiveWorkerSession;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * «Состояние сервера» для администратора: место на диске, резервные копии,
 * планировщик, логи, база, настройки. Каждая проверка — ok / warn / bad.
 */
class SystemController extends Controller
{
    public function index()
    {
        $checks = [];
        $add = function (string $group, string $status, string $title, string $detail = '') use (&$checks) {
            $checks[$group][] = compact('status', 'title', 'detail');
        };

        // ---------------------------------------------------------------- диск
        foreach (array_filter(['Сервер (сайт и копии)' => base_path(), 'Второй носитель копий' => env('BACKUP_EXTERNAL_PATH')]) as $label => $path) {
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
                $add('Резервные копии', 'bad', "Копии на «{$diskName}»", 'Ни одной копии');
                continue;
            }
            $latest = $files->sortByDesc(fn ($f) => $disk->lastModified($f))->first();
            $at = Carbon::createFromTimestamp($disk->lastModified($latest))->setTimezone(config('app.timezone'));
            $hours = (int) $at->diffInHours(now());
            $add('Резервные копии', $hours > 30 ? 'bad' : 'ok', "Копии на «{$diskName}»",
                "Последняя {$at->format('d.m.Y H:i')} (" . VerifyBackup::size($disk->size($latest)) . "), всего {$files->count()}");
        }
        if (! env('BACKUP_EXTERNAL_PATH')) {
            $add('Резервные копии', 'warn', 'Второй носитель', 'Копии только на SD-карте сервера. Подключите флешку и укажите BACKUP_EXTERNAL_PATH в .env');
        }

        $verify = Cache::get(VerifyBackup::CACHE_KEY);
        if (! $verify) {
            $add('Резервные копии', 'warn', 'Проверка копии', 'Ещё не выполнялась (backup:verify, каждую ночь в 02:40)');
        } else {
            $at = Carbon::parse($verify['at'])->setTimezone(config('app.timezone'));
            $add('Резервные копии', $verify['ok'] ? ($at->lt(now()->subHours(30)) ? 'warn' : 'ok') : 'bad',
                'Проверка копии' . ($verify['restore'] ? ' (с пробным восстановлением)' : ''),
                $at->format('d.m.Y H:i') . ' — ' . ($verify['ok'] ? 'в порядке. ' . implode('. ', array_slice($verify['notes'], -2)) : implode('. ', $verify['problems'])));
        }
        if (! config('backup.verify_database')) {
            $add('Резервные копии', 'warn', 'Пробное восстановление', 'Не настроено: создайте пустую базу и укажите её в BACKUP_VERIFY_DATABASE');
        }

        // ---------------------------------------------------------------- планировщик
        $beat = Cache::get('scheduler.heartbeat');
        $beatAge = $beat ? (int) Carbon::parse($beat)->diffInMinutes(now()) : null;
        $add('Планировщик', $beatAge !== null && $beatAge <= 5 ? 'ok' : 'bad', 'Ночные задачи (бэкап, очистка чата и журнала)',
            $beatAge === null ? 'Не запускался: нет строки cron «php artisan schedule:run»' : "Последний запуск {$beatAge} мин назад");

        // ---------------------------------------------------------------- логи
        $logs = collect(glob(storage_path('logs/*.log')) ?: []);
        $logSize = $logs->sum(fn ($f) => filesize($f));
        $add('Логи', $logSize > 200 * 1024 * 1024 ? 'warn' : 'ok', 'Размер логов', VerifyBackup::size($logSize) . ', файлов: ' . $logs->count());
        if (config('logging.channels.stack.channels') === ['single'] || in_array('single', (array) config('logging.channels.stack.channels'), true)) {
            $add('Логи', 'warn', 'Лог одним файлом', 'Файл растёт бесконечно. В .env: LOG_STACK=daily (новый файл каждый день, хранится 14 дней)');
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
            $add('Данные', 'ok', $label, VerifyBackup::size($this->dirSize(storage_path("app/private/{$dir}"))));
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
            $add('Настройки', 'warn', 'Правила пользования', 'Не указана организация: LEGAL_OPERATOR, LEGAL_ADDRESS в .env');
        }
        $add('Настройки', 'ok', 'Версии', 'PHP ' . PHP_VERSION . ', Laravel ' . app()->version());

        // ---------------------------------------------------------------- сейчас
        $online = ActiveWorkerSession::where('last_heartbeat_at', '>=', now()->subMinutes(3))->distinct('employee_id')->count('employee_id');

        return view('system.index', [
            'checks'     => $checks,
            'online'     => $online,
            'serverTime' => now(),
        ]);
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
