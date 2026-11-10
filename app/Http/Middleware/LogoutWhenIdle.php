<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Автовыход при бездействии (общие компьютеры цеха): если пользователь ничего не делал
 * дольше auth.idle_logout_minutes, сессия завершается.
 *
 * Фоновые запросы (опрос чата, «кто онлайн», обновление токена) активностью не считаются.
 * Пока человек печатает или двигает мышью, браузер раз в минуту шлёт /session/ping
 * (public/js/idle.js), так что долгое заполнение формы не приводит к выходу.
 */
class LogoutWhenIdle
{
    private const KEY = 'last_activity_at';

    /** Фоновые запросы — не продлевают сессию. */
    private const BACKGROUND = [
        'chat.summary', 'chat.messages', 'chat.conversations', 'chat.typing', 'chat.users',
        'worker-sessions.heartbeat', 'worker-sessions.occupied',
        'csrf.refresh', 'news.latest', 'games.battleship.state',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $minutes = (int) config('auth.idle_logout_minutes');
        if ($minutes <= 0 || ! Auth::check() || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $last = (int) $session->get(self::KEY, 0);

        if ($last && now()->getTimestamp() - $last > $minutes * 60 && ! $request->routeIs('logout')) {
            Auth::guard('web')->logout();
            $session->invalidate();
            $session->regenerateToken();
            $message = "Вы вышли из системы автоматически: не было действий {$minutes} мин.";

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'idle_logout' => true], 401)
                : redirect()->route('login')->with('status', $message);
        }

        if (! $last || ! $request->routeIs(...self::BACKGROUND)) {
            $session->put(self::KEY, now()->getTimestamp());
        }

        return $next($request);
    }
}
