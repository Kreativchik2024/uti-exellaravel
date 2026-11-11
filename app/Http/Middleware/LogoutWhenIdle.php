<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Автовыход (общие компьютеры цеха):
 *  - при бездействии дольше auth.idle_logout_minutes (по умолчанию 1,5 часа);
 *  - в любом случае через auth.max_session_hours после входа (по умолчанию 12 часов), даже если человек работает.
 *
 * Фоновые запросы (опрос чата, «кто онлайн», обновление токена) активностью не считаются.
 * Пока человек печатает или двигает мышью, браузер раз в минуту шлёт /session/ping
 * (public/js/idle.js), так что долгое заполнение формы не приводит к выходу.
 */
class LogoutWhenIdle
{
    public const KEY = 'last_activity_at';

    /** Когда начат сеанс (вход в учётную запись). */
    public const STARTED = 'session_started_at';

    /** Фоновые запросы — не продлевают сессию. */
    private const BACKGROUND = [
        'chat.summary', 'chat.messages', 'chat.conversations', 'chat.typing', 'chat.users',
        'worker-sessions.heartbeat', 'worker-sessions.occupied',
        'csrf.refresh', 'news.latest', 'games.battleship.state', 'session.status',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $minutes = (int) config('auth.idle_logout_minutes');
        $hours = (int) config('auth.max_session_hours');
        if (! Auth::check() || ! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $now = now()->getTimestamp();
        $last = (int) $session->get(self::KEY, 0);
        $started = (int) $session->get(self::STARTED, 0);
        if (! $started) $session->put(self::STARTED, $started = $now);

        if (! $request->routeIs('logout')) {
            $reason = null;
            if ($hours > 0 && $now - $started > $hours * 3600) {
                $reason = ["Автовыход: сеанс длится больше {$hours} ч", "Вы вышли из системы автоматически: сеанс длится больше {$hours} ч. Войдите заново."];
            } elseif ($minutes > 0 && $last && $now - $last > $minutes * 60) {
                $idle = self::humanMinutes($minutes);
                $reason = ["Автовыход: не было действий {$idle}", "Вы вышли из системы автоматически: не было действий {$idle}."];
            }
            if ($reason) {
                \App\Models\AuditLog::write('auth', 'logout', $reason[0], ['section_id' => null]);
                Auth::guard('web')->logout();
                $session->invalidate();
                $session->regenerateToken();
                $session->flash('status', $reason[1]);   // фоновый запрос → страница перезагрузится и покажет причину

                return $request->expectsJson()
                    ? response()->json(['message' => $reason[1], 'idle_logout' => true], 401)
                    : redirect()->route('login');
            }
        }

        if (! $last || ! $request->routeIs(...self::BACKGROUND)) {
            $session->put(self::KEY, now()->getTimestamp());
        }

        return $next($request);
    }

    /** 90 → «1 ч 30 мин», 30 → «30 мин». */
    public static function humanMinutes(int $m): string
    {
        $h = intdiv($m, 60);
        $rest = $m % 60;

        return trim(($h ? "{$h} ч " : '') . ($rest || ! $h ? "{$rest} мин" : ''));
    }
}
