<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * Обслуживание, запускаемое кнопками со страницы «Состояние сервера» (в фоне).
 * Ход и результат — в Cache (system.task), страница опрашивает его.
 */
class SystemTask extends Command
{
    protected $signature = 'system:task {task}';

    protected $description = 'Выполнить задачу обслуживания со страницы «Состояние сервера»';

    public const CACHE_KEY = 'system.task';

    /** Разрешённые задачи: ключ => [название, [команды artisan с параметрами]]. */
    public const TASKS = [
        'backup-db'   => ['Копия базы', [['backup:run', ['--only-db' => true, '--disable-notifications' => true]]]],
        'backup-full' => ['Полная копия (база + файлы)', [['backup:run', ['--disable-notifications' => true]]]],
        'verify'      => ['Проверка полной копии', [['backup:verify', ['--full' => true]]]],
        'restore'     => ['Пробное восстановление', [['backup:verify', ['--full' => true, '--restore' => true]]]],
        'clean'       => ['Удаление копий старше 7 дней', [['backup:clean', ['--disable-notifications' => true]]]],
        'chat-prune'  => ['Очистка чата старше 7 дней', [['chat:prune', []]]],
        'cache'       => ['Обновление кеша сайта', [['optimize:clear', []], ['optimize', []]]],
    ];

    public function handle(): int
    {
        $key = $this->argument('task');
        abort_unless(isset(self::TASKS[$key]), 404);
        [$label, $steps] = self::TASKS[$key];

        $state = ['task' => $key, 'label' => $label, 'status' => 'running', 'started_at' => now()->toIso8601String(), 'finished_at' => null, 'output' => ''];
        Cache::forever(self::CACHE_KEY, $state);

        $ok = true;
        $output = '';
        @set_time_limit(1800);
        foreach ($steps as [$command, $params]) {
            try {
                $code = Artisan::call($command, $params);
                $output .= Artisan::output();
                if ($code !== 0) { $ok = false; break; }
            } catch (\Throwable $e) {
                $output .= $e->getMessage();
                $ok = false;
                break;
            }
        }

        // Последние строки вывода — для показа на странице
        $lines = array_slice(array_filter(array_map('trim', preg_split('/\R/u', strip_tags($output)))), -6);
        Cache::forever(self::CACHE_KEY, array_merge($state, [
            'status'      => $ok ? 'done' : 'failed',
            'finished_at' => now()->toIso8601String(),
            'output'      => mb_strimwidth(implode("\n", $lines), 0, 1500, '…'),
        ]));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
