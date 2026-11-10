<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Расписание. Сервер работает 08:00–20:00 (ночью выключен), поэтому всё —
| в рабочее время: ночью задачи не выполнились бы, а пропущенные не догоняются.
|--------------------------------------------------------------------------
| Резервные копии:
| • 08:05, 09:05 … 19:05 — копия базы (все записи учёта, чат, журнал; меньше 1 МБ);
|   в :05 — чтобы сервер успел загрузиться после включения в 08:00;
| • 19:50, в конце дня — полная копия: база + файлы (прошивки, картинки, настройки);
| • хранится 7 дней, более старые удаляются утром (config/backup.php → cleanup).
*/

Schedule::command('backup:run --only-db --disable-notifications')
    ->cron('5 8-19 * * *')->withoutOverlapping(30);   // в :05 с 08 до 19 часов
Schedule::command('backup:run --disable-notifications')
    ->dailyAt('19:50')->withoutOverlapping(60);

// Утро: проверка вчерашней полной копии, очистка старых копий, чата и журнала
Schedule::command('backup:verify --full')->dailyAt('08:15');
Schedule::command('backup:clean --disable-notifications')->dailyAt('08:20');
Schedule::command('backup:monitor')->dailyAt('08:25');
Schedule::command('chat:prune')->dailyAt('08:30');      // история чата — 7 дней
Schedule::command('audit:prune')->dailyAt('08:35');     // журнал действий — год
Schedule::command('backup:verify --full --restore')->dailyAt('08:45')->when(fn () => (bool) config('backup.verify_database'));

// Планировщик жив: отметка раз в минуту (страница «Состояние сервера»)
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::forever('scheduler.heartbeat', now()->toIso8601String()))
    ->everyMinute()->name('scheduler-heartbeat');

/*
|--------------------------------------------------------------------------
| Проверка здоровья бэкапов (необязательно)
|--------------------------------------------------------------------------
*/
