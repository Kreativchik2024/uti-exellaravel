<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\ConfirmablePasswordController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\Auth\RegisteredUserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('register', [RegisteredUserController::class, 'create'])
        ->name('register');

    Route::post('register', [RegisteredUserController::class, 'store']);

    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    // Rate limiting: 5 попыток в минуту на email+IP
    Route::post('login', [AuthenticatedSessionController::class, 'store'])
        ->middleware('throttle:login');

});

// Восстановление пароля по почте. Выключено по умолчанию: в изолированной сети почты нет,
// ссылки сброса писались бы в лог, а форма подсказывала бы, какие email зарегистрированы.
// Пароль сбрасывает администратор (Пользователи → «Сбросить»).
// Включить, когда будет настроена почта: PASSWORD_RESET_EMAIL=true в .env.
if (config('auth.password_reset_by_email')) {
    Route::middleware('guest')->group(function () {
        Route::get('forgot-password', [PasswordResetLinkController::class, 'create'])
            ->name('password.request');

        Route::post('forgot-password', [PasswordResetLinkController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('password.email');

        Route::get('reset-password/{token}', [NewPasswordController::class, 'create'])
            ->name('password.reset');

        Route::post('reset-password', [NewPasswordController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('password.store');
    });
}

Route::middleware('auth')->group(function () {

    Route::get('confirm-password', [ConfirmablePasswordController::class, 'show'])
        ->name('password.confirm');

    Route::post('confirm-password', [ConfirmablePasswordController::class, 'store']);

    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});