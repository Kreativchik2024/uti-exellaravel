<?php

use App\Http\Controllers\StationController;
use App\Http\Middleware\StationKey;
use Illuminate\Support\Facades\Route;

// Программы на рабочих станциях: флешер камер и считыватель номера камеры.
Route::prefix('station')->middleware([StationKey::class, 'throttle:120,1'])->group(function () {
    Route::get('/ping',               [StationController::class, 'ping']);
    Route::get('/employees',          [StationController::class, 'employees']);
    Route::post('/verify',            [StationController::class, 'verify']);
    Route::get('/fault-codes',        [StationController::class, 'faultCodes']);
    Route::get('/camera/{serial}',    [StationController::class, 'camera'])->where('serial', '[A-Za-z0-9._-]{1,120}');
    Route::post('/camera-flash',      [StationController::class, 'cameraFlash']);
    Route::post('/camera-bracket',    [StationController::class, 'cameraBracket']);
});
