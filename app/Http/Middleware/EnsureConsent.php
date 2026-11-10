<?php

namespace App\Http\Middleware;

use App\Http\Controllers\ConsentController;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Без согласия с правилами и на обработку персональных данных сайтом пользоваться нельзя.
 *
 * Проверка на сервере, а не только окном в браузере: окно можно скрыть через инструменты
 * разработчика, а запросы (чат, записи, статистика) отправить напрямую. Пока согласия нет,
 * вместо любой страницы отдаётся только окно согласия, а запросы данных получают 403.
 *
 * Доступно без согласия: сами правила, кнопка «Принять», обновление CSRF-токена и выход.
 */
class EnsureConsent
{
    private const ALWAYS_ALLOWED = ['legal.rules', 'consent.accept', 'csrf.refresh', 'logout'];

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->routeIs(...self::ALWAYS_ALLOWED) || ! ConsentController::required($request)) {
            return $next($request);
        }

        $message = 'Чтобы пользоваться сайтом, примите правила пользования и согласие на обработку персональных данных.';

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'consent_required' => true], 403);
        }

        // Страница целиком заменяется окном согласия; после «Принять» — возврат сюда же
        $status = $request->isMethod('GET') || $request->isMethod('HEAD') ? 200 : 403;

        return response()->view('legal.gate', [], $status);
    }
}
