<?php

use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Ежедневный бэкап БД и файлов (в 02:00 ночи)
|--------------------------------------------------------------------------
*/

Schedule::command('backup:clean')->daily()->at('01:30');
Schedule::command('backup:run')->daily()->at('02:00');
Schedule::command('backup:monitor')->daily()->at('03:00');

// Чат: история хранится 7 дней — старые сообщения и их файлы удаляются
Schedule::command('chat:prune')->daily()->at('03:30');

// Проверка последней копии: каждую ночь — целостность; по воскресеньям — пробное восстановление
Schedule::command('backup:verify')->daily()->at('02:40');
Schedule::command('backup:verify --restore')->sundays()->at('04:00')->when(fn () => (bool) config('backup.verify_database'));

// Планировщик жив: отметка раз в минуту (страница «Состояние сервера»)
Schedule::call(fn () => \Illuminate\Support\Facades\Cache::forever('scheduler.heartbeat', now()->toIso8601String()))
    ->everyMinute()->name('scheduler-heartbeat');

// Журнал действий: записи старше года удаляются
Schedule::command('audit:prune')->daily()->at('03:40');

/*
|--------------------------------------------------------------------------
| Проверка здоровья бэкапов (необязательно)
|--------------------------------------------------------------------------
*/
