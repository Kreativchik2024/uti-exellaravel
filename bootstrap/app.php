<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role'    => \App\Http\Middleware\RoleMiddleware::class,
            'workers' => \App\Http\Middleware\EnsureWorkersSelected::class,
            'section.feature' => \App\Http\Middleware\EnsureSectionFeature::class,
        ]);

        // Single session: применяется ко всем web-запросам.
        // Внутри middleware — проверка: только для role=executor.
        $middleware->web(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\EnsureUserIsActive::class,
            \App\Http\Middleware\EnsureSingleSession::class,
            \App\Http\Middleware\EnsureConsent::class,   // без согласия с правилами — только окно согласия
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // 419 (устаревший CSRF-токен): вместо страницы «Page Expired» —
        // возврат на форму с сохранёнными данными и понятным сообщением.
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            $message = 'Страница устарела. Данные сохранены — отправьте форму ещё раз.';

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'error' => $message, 'csrf' => true], 419);
            }

            // Пользователь хотел выйти — просто завершаем сессию
            if ($request->routeIs('logout')) {
                auth()->guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login');
            }

            if ($request->is('login', 'register')) {
                return redirect($request->path() === 'register' ? route('register') : route('login'))
                    ->withInput($request->only('name', 'email', 'remember'))
                    ->withErrors(['email' => 'Страница входа устарела. Введите пароль ещё раз.']);
            }

            return redirect()->back()
                ->withInput($request->except(['_token', 'password', 'password_confirmation', 'current_password', 'pin']))
                ->with('csrf_expired', $message);
        });
    })->create();