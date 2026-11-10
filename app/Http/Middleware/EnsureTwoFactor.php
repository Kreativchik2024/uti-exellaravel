<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Вход с одноразовым кодом из приложения на телефоне.
 *
 *  - Включён у пользователя — после пароля нужен код (в каждой новой сессии, в т.ч. «запомнить меня»).
 *  - Роль из auth.two_factor_roles (по умолчанию admin), а защита не настроена —
 *    сначала настройка, без неё работать нельзя.
 */
class EnsureTwoFactor
{
    public const SESSION_KEY = 'two_factor_passed';

    private const ALLOWED = ['two-factor.*', 'logout', 'legal.rules', 'consent.accept', 'csrf.refresh', 'session.ping'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || $request->routeIs(...self::ALLOWED)) {
            return $next($request);
        }

        if ($user->hasTwoFactor()) {
            if ((int) $request->session()->get(self::SESSION_KEY) === $user->id) {
                return $next($request);
            }
            $route = 'two-factor.challenge';
            $message = 'Введите код из приложения на телефоне.';
        } elseif ($user->requiresTwoFactor()) {
            $route = 'two-factor.setup';
            $message = 'Для администратора обязателен вход с кодом из приложения на телефоне. Настройте его.';
        } else {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => $message, 'two_factor' => true], 403);
        }
        if ($request->isMethod('GET')) {
            $request->session()->put('url.intended', $request->fullUrl());
        }

        return redirect()->route($route);
    }
}
