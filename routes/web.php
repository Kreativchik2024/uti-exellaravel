<?php

use App\Http\Controllers\ChatController;
use App\Http\Controllers\ConsentController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FirmwareCategoryController;
use App\Http\Controllers\FirmwareController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\SectionController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkerSessionController;
use App\Http\Controllers\WorkstationController;
use Illuminate\Support\Facades\Route;

// Свежий CSRF-токен для долго открытых страниц (public/js/csrf.js) — защита от 419
Route::get('/csrf-token', fn () => response()
    ->json(['token' => csrf_token()])
    ->header('Cache-Control', 'no-store, private'))
    ->middleware('throttle:60,1')
    ->name('csrf.refresh');

// Правила пользования, cookie и согласие на обработку персональных данных (доступно и гостям)
Route::get('/rules', [ConsentController::class, 'rules'])->name('legal.rules');
Route::post('/consent', [ConsentController::class, 'accept'])->middleware('throttle:20,1')->name('consent.accept');

// Пользователь активен (public/js/idle.js) — продлевает сессию при автовыходе по бездействию
Route::get('/session/ping', fn () => response()->json(['ok' => true])->header('Cache-Control', 'no-store'))
    ->middleware(['auth', 'throttle:20,1'])->name('session.ping');

// Сколько секунд пользователь бездействует по данным сервера (все вкладки и устройства) — перед автовыходом
Route::get('/session/status', fn () => response()->json([
    'idle_seconds'  => max(0, now()->getTimestamp() - (int) session(\App\Http\Middleware\LogoutWhenIdle::KEY, now()->getTimestamp())),
    'limit_seconds' => (int) config('auth.idle_logout_minutes') * 60,
])->header('Cache-Control', 'no-store'))->middleware(['auth', 'throttle:30,1'])->name('session.status');

// Выбор текущего участка (в шапке)
Route::post('/section/switch', [SectionController::class, 'switch'])->middleware('auth')->name('section.switch');

Route::middleware('auth')->group(function () {
    Route::get('/workstation/select',  [WorkstationController::class, 'show'])->name('workstation.show');
    Route::post('/workstation/select', [WorkstationController::class, 'store'])->name('workstation.store');
    Route::post('/workstation/skip',   [WorkstationController::class, 'skip'])->name('workstation.skip');
    Route::post('/workstation/clear',  [WorkstationController::class, 'clear'])->name('workstation.clear');

    // PIN-проверки: доступны и до workers (на /workstation/select)
    // Ограничение попыток: защита ПИН (4–6 цифр) от перебора
    Route::post('/records-pin-check',  [RecordController::class, 'checkEmployeePin'])
        ->middleware('throttle:pin')
        ->name('records.pinCheck');

    // Онлайн-присутствие исполнителей
    Route::post('/worker-sessions/heartbeat', [WorkerSessionController::class, 'heartbeat'])->name('worker-sessions.heartbeat');
    Route::get('/worker-sessions/occupied',   [WorkerSessionController::class, 'occupied'])->name('worker-sessions.occupied');
    Route::post('/worker-sessions/claim',     [WorkerSessionController::class, 'claim'])->name('worker-sessions.claim');
    Route::post('/worker-sessions/release',   [WorkerSessionController::class, 'release'])->name('worker-sessions.release');
});

Route::get('/', [DashboardController::class, 'index'])
    ->middleware(['auth', 'workers'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile',      [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',    [ProfileController::class, 'update'])->name('profile.update');
    Route::post('/profile/pin', [ProfileController::class, 'updatePin'])->name('profile.pin');
    Route::delete('/profile',   [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Админские разделы: убран 'workers', чтобы не блокировались при входе
Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/users',                     [UserController::class, 'index'])->name('users.index');
    Route::patch('/users/{user}/role',       [UserController::class, 'updateRole'])->name('users.role');
    Route::patch('/users/{user}/approve',    [UserController::class, 'approve'])->name('users.approve');
    Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::patch('/users/{user}/password',   [UserController::class, 'resetPassword'])->name('users.password');
    Route::patch('/users/{user}/pin',        [UserController::class, 'resetPin'])->name('users.pin');
    Route::delete('/users/{user}',           [UserController::class, 'destroy'])->name('users.destroy');

    Route::get('/employees/export',   [EmployeeController::class, 'export'])->name('employees.export');
    Route::get('/employees/template', [EmployeeController::class, 'template'])->name('employees.template');
    Route::get('/employees/import',   [EmployeeController::class, 'importForm'])->name('employees.importForm');
    Route::post('/employees/import',  [EmployeeController::class, 'import'])->name('employees.import');

    Route::get('/employees',                 [EmployeeController::class, 'index'])->name('employees.index');
    Route::get('/employees/create',          [EmployeeController::class, 'create'])->name('employees.create');
    Route::post('/employees',                [EmployeeController::class, 'store'])->name('employees.store');
    Route::get('/employees/{employee}/edit', [EmployeeController::class, 'edit'])->name('employees.edit');
    Route::put('/employees/{employee}',      [EmployeeController::class, 'update'])->name('employees.update');
    Route::delete('/employees/{employee}',   [EmployeeController::class, 'destroy'])->name('employees.destroy');

    Route::get('/audit', [\App\Http\Controllers\AuditController::class, 'index'])->name('audit.index');

    // Справочник кодов неисправностей
    Route::get('/fault-codes',                [\App\Http\Controllers\FaultCodeController::class, 'index'])->name('fault-codes.index');
    Route::post('/fault-codes',               [\App\Http\Controllers\FaultCodeController::class, 'store'])->name('fault-codes.store');
    Route::get('/fault-codes/template',       [\App\Http\Controllers\FaultCodeController::class, 'template'])->name('fault-codes.template');
    Route::get('/fault-codes/export',         [\App\Http\Controllers\FaultCodeController::class, 'export'])->name('fault-codes.export');
    Route::post('/fault-codes/wipe',          [\App\Http\Controllers\FaultCodeController::class, 'wipe'])->name('fault-codes.wipe');
    Route::post('/fault-codes/import',        [\App\Http\Controllers\FaultCodeController::class, 'import'])->middleware('throttle:10,1')->name('fault-codes.import');
    Route::patch('/fault-codes/{faultCode}',  [\App\Http\Controllers\FaultCodeController::class, 'update'])->name('fault-codes.update');
    Route::patch('/fault-codes/{faultCode}/approve', [\App\Http\Controllers\FaultCodeController::class, 'approve'])->name('fault-codes.approve');
    Route::post('/fault-codes/{faultCode}/merge',    [\App\Http\Controllers\FaultCodeController::class, 'merge'])->name('fault-codes.merge');
    Route::post('/fault-code-groups',                [\App\Http\Controllers\FaultCodeController::class, 'storeGroup'])->name('fault-codes.groups.store');
    Route::patch('/fault-code-groups/{group}',       [\App\Http\Controllers\FaultCodeController::class, 'updateGroup'])->name('fault-codes.groups.update');
    Route::delete('/fault-code-groups/{group}',      [\App\Http\Controllers\FaultCodeController::class, 'destroyGroup'])->name('fault-codes.groups.destroy');
    Route::delete('/fault-codes/{faultCode}', [\App\Http\Controllers\FaultCodeController::class, 'destroy'])->name('fault-codes.destroy');
    Route::get('/system', [\App\Http\Controllers\SystemController::class, 'index'])->name('system.index');
    Route::get('/system/status', [\App\Http\Controllers\SystemController::class, 'status'])->name('system.status');
    Route::get('/system/metrics', [\App\Http\Controllers\SystemController::class, 'metrics'])->middleware('throttle:60,1')->name('system.metrics');
    Route::get('/system/errors', [\App\Http\Controllers\SystemController::class, 'errors'])->name('system.errors');
    Route::post('/system/run/{task}', [\App\Http\Controllers\SystemController::class, 'run'])->middleware('throttle:10,1')->name('system.run');
    Route::get('/system/backup/download', [\App\Http\Controllers\SystemController::class, 'downloadBackup'])->middleware('password.confirm')->name('system.backup.download');
    Route::get('/system/logs/download', [\App\Http\Controllers\SystemController::class, 'downloadLog'])->name('system.logs.download');
    Route::post('/system/logs/clear', [\App\Http\Controllers\SystemController::class, 'clearLogs'])->name('system.logs.clear');

    Route::get('/sections',              [SectionController::class, 'index'])->name('sections.index');
    Route::post('/sections',             [SectionController::class, 'store'])->name('sections.store');
    Route::patch('/sections/{section}',  [SectionController::class, 'update'])->name('sections.update');
    Route::patch('/users/{user}/sections', [SectionController::class, 'assign'])->name('users.sections');

    Route::get('/firmware/categories',               [FirmwareCategoryController::class, 'index'])->middleware('section.feature:firmware')->name('firmware.categories.index');
    Route::post('/firmware/categories',              [FirmwareCategoryController::class, 'store'])->middleware('section.feature:firmware')->name('firmware.categories.store');
    Route::delete('/firmware/categories/{category}', [FirmwareCategoryController::class, 'destroy'])->middleware('section.feature:firmware')->name('firmware.categories.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/news/latest', [NewsController::class, 'latest'])->name('news.latest');
});

Route::get('/news', [NewsController::class, 'index'])->name('news.index');

Route::get('/news/{news}', [NewsController::class, 'show'])
    ->name('news.show')
    ->whereNumber('news');

// Мастер и Админ: убран 'workers' для действий с контентом
Route::middleware(['auth', 'role:admin,master'])->group(function () {
    Route::get('/news/create',                   [NewsController::class, 'create'])->name('news.create');
    Route::post('/news',                         [NewsController::class, 'store'])->name('news.store');
    Route::get('/news/{news}/edit',              [NewsController::class, 'edit'])->name('news.edit');
    Route::put('/news/{news}',                   [NewsController::class, 'update'])->name('news.update');
    Route::delete('/news/{news}',                [NewsController::class, 'destroy'])->name('news.destroy');
    Route::delete('/news/{news}/images/{image}', [NewsController::class, 'destroyImage'])->name('news.images.destroy');

    Route::get('/firmware/create',             [FirmwareController::class, 'create'])->middleware('section.feature:firmware')->name('firmware.create');
    Route::post('/firmware',                   [FirmwareController::class, 'store'])->middleware('section.feature:firmware')->name('firmware.store');
    Route::patch('/firmware/{version}/status', [FirmwareController::class, 'status'])->middleware('section.feature:firmware')->name('firmware.status');
    Route::delete('/firmware/{version}',       [FirmwareController::class, 'destroy'])->middleware('section.feature:firmware')->name('firmware.destroy');
});

Route::middleware(['auth', 'workers'])->group(function () {
    Route::get('/firmware',                    [FirmwareController::class, 'index'])->middleware('section.feature:firmware')->name('firmware.index');
    Route::get('/firmware/{version}/download', [FirmwareController::class, 'download'])->middleware('section.feature:firmware')->name('firmware.download');

    Route::get('/search', [\App\Http\Controllers\SearchController::class, 'index'])->name('search');

    // Отчёты мастера: смена и анализ брака (по текущему участку)
    Route::get('/reports/shift',   [\App\Http\Controllers\ReportController::class, 'shift'])->middleware('role:admin,master')->name('reports.shift');
    Route::get('/reports/defects', [\App\Http\Controllers\ReportController::class, 'defects'])->middleware('role:admin,master')->name('reports.defects');

    Route::get('/records/{sheet}',           [RecordController::class, 'index'])->name('records.index');
    Route::get('/records/{sheet}/create',    [RecordController::class, 'create'])->name('records.create');
    Route::post('/records/{sheet}',          [RecordController::class, 'store'])->name('records.store');
    Route::get('/records/{sheet}/lookup',    [RecordController::class, 'lookup'])->name('records.lookup');
    Route::get('/records/{sheet}/fault-codes', [RecordController::class, 'faultCodes'])->middleware('throttle:120,1')->name('records.faultCodes');
    Route::get('/records/{sheet}/{id}',      [RecordController::class, 'show'])->name('records.show');
    Route::get('/records/{sheet}/{id}/edit', [RecordController::class, 'edit'])->name('records.edit');
    Route::put('/records/{sheet}/{id}',      [RecordController::class, 'update'])->name('records.update');
    Route::delete('/records/{sheet}/{id}',   [RecordController::class, 'destroy'])->name('records.destroy');
});

Route::middleware(['auth', 'workers'])->group(function () {
    Route::get('/stats',                [StatsController::class, 'index'])->name('stats.index');
    Route::get('/stats/export',         [StatsController::class, 'export'])->name('stats.export');
    Route::get('/stats/zone1',          [StatsController::class, 'zone1'])->name('stats.zone1');
    Route::get('/stats/zone2',          [StatsController::class, 'zone2'])->name('stats.zone2');
    Route::get('/stats/zone2-chart',    [StatsController::class, 'zone2Chart'])->name('stats.zone2Chart');
    Route::get('/stats/zone2-worker',   [StatsController::class, 'zone2Worker'])->name('stats.zone2Worker');
    Route::get('/stats/zone3',          [StatsController::class, 'zone3'])->name('stats.zone3');
    Route::get('/stats/zone4',          [StatsController::class, 'zone4'])->name('stats.zone4');
    Route::get('/stats/zone4-records',  [StatsController::class, 'zone4Records'])->name('stats.zone4Records');
    Route::get('/stats/zone5',          [StatsController::class, 'zone5'])->name('stats.zone5');
    Route::get('/stats/zone5-chart',    [StatsController::class, 'zone5Chart'])->name('stats.zone5Chart');
    Route::get('/stats/zone6',          [StatsController::class, 'zone6'])->name('stats.zone6');
});

// Игры (меню профиля) — отдельно от рабочей статистики; админ включает и выключает
Route::middleware(['auth', 'games'])->prefix('games')->name('games.')->group(function () {
    Route::get('/',                 [\App\Http\Controllers\GameController::class, 'index'])->name('index');
    Route::post('/toggle',          [\App\Http\Controllers\GameController::class, 'toggle'])->name('toggle');

    Route::get('/minesweeper',      [\App\Http\Controllers\GameController::class, 'minesweeper'])->name('minesweeper');
    Route::post('/minesweeper',     [\App\Http\Controllers\GameController::class, 'minesweeperStart'])->middleware('throttle:30,1')->name('minesweeper.start');
    Route::post('/minesweeper/{session}/open', [\App\Http\Controllers\GameController::class, 'minesweeperOpen'])->middleware('throttle:600,1')->name('minesweeper.open');

    Route::get('/snake',            [\App\Http\Controllers\GameController::class, 'snake'])->name('snake');
    Route::post('/snake',           [\App\Http\Controllers\GameController::class, 'snakeStart'])->middleware('throttle:30,1')->name('snake.start');
    Route::post('/snake/{session}/finish', [\App\Http\Controllers\GameController::class, 'snakeFinish'])->middleware('throttle:30,1')->name('snake.finish');

    Route::post('/battleship/bot',      [\App\Http\Controllers\BattleshipController::class, 'vsBot'])->middleware('throttle:20,1')->name('battleship.bot');
    Route::post('/battleship/invite',   [\App\Http\Controllers\BattleshipController::class, 'invite'])->middleware('throttle:10,1')->name('battleship.invite');
    Route::get('/battleship/{game}',    [\App\Http\Controllers\BattleshipController::class, 'show'])->name('battleship.show');
    Route::get('/battleship/{game}/state', [\App\Http\Controllers\BattleshipController::class, 'state'])->name('battleship.state');
    Route::post('/battleship/{game}/accept',   [\App\Http\Controllers\BattleshipController::class, 'accept'])->name('battleship.accept');
    Route::post('/battleship/{game}/decline',  [\App\Http\Controllers\BattleshipController::class, 'decline'])->name('battleship.decline');
    Route::post('/battleship/{game}/shuffle',  [\App\Http\Controllers\BattleshipController::class, 'shuffle'])->middleware('throttle:60,1')->name('battleship.shuffle');
    Route::post('/battleship/{game}/place',    [\App\Http\Controllers\BattleshipController::class, 'place'])->middleware('throttle:60,1')->name('battleship.place');
    Route::post('/battleship/{game}/ready',    [\App\Http\Controllers\BattleshipController::class, 'ready'])->name('battleship.ready');
    Route::post('/battleship/{game}/shoot',    [\App\Http\Controllers\BattleshipController::class, 'shoot'])->middleware('throttle:200,1')->name('battleship.shoot');
    Route::post('/battleship/{game}/surrender', [\App\Http\Controllers\BattleshipController::class, 'surrender'])->name('battleship.surrender');
});

// Чат: общий канал и личные сообщения (опрос с клиента)
Route::middleware('auth')->prefix('chat')->name('chat.')->group(function () {
    Route::get('/summary',       [ChatController::class, 'summary'])->name('summary');
    Route::get('/conversations', [ChatController::class, 'conversations'])->name('conversations');
    Route::get('/users',         [ChatController::class, 'users'])->name('users');
    Route::get('/messages',      [ChatController::class, 'messages'])->name('messages');
    Route::post('/messages',     [ChatController::class, 'send'])->middleware('throttle:chat')->name('send');
    Route::post('/read',         [ChatController::class, 'read'])->name('read');
    Route::post('/messages/{message}/react', [ChatController::class, 'react'])->middleware('throttle:60,1')->name('react');
    Route::post('/messages/{message}/pin',   [ChatController::class, 'pin'])->middleware('throttle:30,1')->name('pin');
    Route::post('/typing',       [ChatController::class, 'typing'])->middleware('throttle:40,1')->name('typing');
    Route::get('/attachments/{attachment}/{variant?}', [ChatController::class, 'attachment'])
        ->whereIn('variant', ['thumb'])->name('attachment');
});

Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->middleware('auth')
    ->name('media.show');

require __DIR__.'/auth.php';