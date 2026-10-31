<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EmployeeController;
use App\Http\Controllers\FirmwareCategoryController;
use App\Http\Controllers\FirmwareController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WorkstationController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    Route::get('/workstation/select',  [WorkstationController::class, 'show'])->name('workstation.show');
    Route::post('/workstation/select', [WorkstationController::class, 'store'])->name('workstation.store');
    Route::post('/workstation/skip',   [WorkstationController::class, 'skip'])->name('workstation.skip');
    Route::post('/workstation/clear',  [WorkstationController::class, 'clear'])->name('workstation.clear');
});

Route::get('/', [DashboardController::class, 'index'])
    ->middleware(['auth', 'workers'])
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'workers', 'role:admin'])->group(function () {
    Route::get('/users',                     [UserController::class, 'index'])->name('users.index');
    Route::patch('/users/{user}/role',       [UserController::class, 'updateRole'])->name('users.role');
    Route::patch('/users/{user}/approve',    [UserController::class, 'approve'])->name('users.approve');
    Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::patch('/users/{user}/password',   [UserController::class, 'resetPassword'])->name('users.password');
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
});

Route::middleware('auth')->group(function () {
    Route::get('/news/latest', [NewsController::class, 'latest'])->name('news.latest');
});

Route::get('/news', [NewsController::class, 'index'])->name('news.index');

Route::get('/news/{news}', [NewsController::class, 'show'])
    ->name('news.show')
    ->whereNumber('news');

Route::middleware(['auth', 'workers', 'role:admin,master'])->group(function () {
    Route::get('/news/create',                   [NewsController::class, 'create'])->name('news.create');
    Route::post('/news',                         [NewsController::class, 'store'])->name('news.store');
    Route::get('/news/{news}/edit',              [NewsController::class, 'edit'])->name('news.edit');
    Route::put('/news/{news}',                   [NewsController::class, 'update'])->name('news.update');
    Route::delete('/news/{news}',                [NewsController::class, 'destroy'])->name('news.destroy');
    Route::delete('/news/{news}/images/{image}', [NewsController::class, 'destroyImage'])->name('news.images.destroy');
});

Route::middleware(['auth', 'workers'])->group(function () {
    Route::get('/firmware',                    [FirmwareController::class, 'index'])->name('firmware.index');
    Route::get('/firmware/{version}/download', [FirmwareController::class, 'download'])->name('firmware.download');
});

Route::middleware(['auth', 'workers', 'role:admin,master'])->group(function () {
    Route::get('/firmware/create',             [FirmwareController::class, 'create'])->name('firmware.create');
    Route::post('/firmware',                   [FirmwareController::class, 'store'])->name('firmware.store');
    Route::patch('/firmware/{version}/status', [FirmwareController::class, 'status'])->name('firmware.status');
    Route::delete('/firmware/{version}',       [FirmwareController::class, 'destroy'])->name('firmware.destroy');
});

Route::middleware(['auth', 'workers', 'role:admin'])->group(function () {
    Route::get('/firmware/categories',               [FirmwareCategoryController::class, 'index'])->name('firmware.categories.index');
    Route::post('/firmware/categories',              [FirmwareCategoryController::class, 'store'])->name('firmware.categories.store');
    Route::delete('/firmware/categories/{category}', [FirmwareCategoryController::class, 'destroy'])->name('firmware.categories.destroy');
});

Route::middleware(['auth', 'workers'])->group(function () {
    Route::get('/records/{sheet}',           [RecordController::class, 'index'])->name('records.index');
    Route::get('/records/{sheet}/create',    [RecordController::class, 'create'])->name('records.create');
    Route::post('/records/{sheet}',          [RecordController::class, 'store'])->name('records.store');
    Route::get('/records/{sheet}/lookup',    [RecordController::class, 'lookup'])->name('records.lookup');
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

Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->middleware('auth')
    ->name('media.show');

require __DIR__.'/auth.php';