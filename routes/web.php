<?php

use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FirmwareCategoryController;
use App\Http\Controllers\FirmwareController;
use App\Http\Controllers\MediaController;
use App\Http\Controllers\NewsController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RecordController;
use App\Http\Controllers\StatsController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DashboardController::class, 'index'])
    ->middleware('auth')
    ->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile',    [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile',  [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/users',                     [UserController::class, 'index'])->name('users.index');
    Route::patch('/users/{user}/role',       [UserController::class, 'updateRole'])->name('users.role');
    Route::patch('/users/{user}/approve',    [UserController::class, 'approve'])->name('users.approve');
    Route::patch('/users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::patch('/users/{user}/password',   [UserController::class, 'resetPassword'])->name('users.password');
    Route::delete('/users/{user}',           [UserController::class, 'destroy'])->name('users.destroy');
});

Route::get('/news', [NewsController::class, 'index'])->name('news.index');

Route::middleware(['auth', 'role:admin,master'])->group(function () {
    Route::get('/news/create',                   [NewsController::class, 'create'])->name('news.create');
    Route::post('/news',                         [NewsController::class, 'store'])->name('news.store');
    Route::get('/news/{news}/edit',              [NewsController::class, 'edit'])->name('news.edit');
    Route::put('/news/{news}',                   [NewsController::class, 'update'])->name('news.update');
    Route::delete('/news/{news}',                [NewsController::class, 'destroy'])->name('news.destroy');
    Route::delete('/news/{news}/images/{image}', [NewsController::class, 'destroyImage'])->name('news.images.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/firmware',                    [FirmwareController::class, 'index'])->name('firmware.index');
    Route::get('/firmware/{version}/download', [FirmwareController::class, 'download'])->name('firmware.download');
});

Route::middleware(['auth', 'role:admin,master'])->group(function () {
    Route::get('/firmware/create',             [FirmwareController::class, 'create'])->name('firmware.create');
    Route::post('/firmware',                   [FirmwareController::class, 'store'])->name('firmware.store');
    Route::patch('/firmware/{version}/status', [FirmwareController::class, 'status'])->name('firmware.status');
    Route::delete('/firmware/{version}',       [FirmwareController::class, 'destroy'])->name('firmware.destroy');
});

Route::middleware(['auth', 'role:admin'])->group(function () {
    Route::get('/firmware/categories',               [FirmwareCategoryController::class, 'index'])->name('firmware.categories.index');
    Route::post('/firmware/categories',              [FirmwareCategoryController::class, 'store'])->name('firmware.categories.store');
    Route::delete('/firmware/categories/{category}', [FirmwareCategoryController::class, 'destroy'])->name('firmware.categories.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/records/{sheet}',           [RecordController::class, 'index'])->name('records.index');
    Route::get('/records/{sheet}/create',    [RecordController::class, 'create'])->name('records.create');
    Route::post('/records/{sheet}',          [RecordController::class, 'store'])->name('records.store');
    Route::get('/records/{sheet}/{id}/edit', [RecordController::class, 'edit'])->name('records.edit');
    Route::put('/records/{sheet}/{id}',      [RecordController::class, 'update'])->name('records.update');
    Route::delete('/records/{sheet}/{id}',   [RecordController::class, 'destroy'])->name('records.destroy');
});

Route::middleware('auth')->group(function () {
    Route::get('/stats',        [StatsController::class, 'index'])->name('stats.index');
    Route::get('/stats/export', [StatsController::class, 'export'])->name('stats.export');
});

Route::get('/media/{path}', [MediaController::class, 'show'])
    ->where('path', '.*')
    ->middleware('auth')
    ->name('media.show');

require __DIR__.'/auth.php';