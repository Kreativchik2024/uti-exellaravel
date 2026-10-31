<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureSingleSession
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isExecutor()) {
            $currentSessionId = $request->session()->getId();

            // Первый вход: записываем текущую сессию
            if (empty($user->session_id)) {
                $user->forceFill(['session_id' => $currentSessionId])->saveQuietly();
            }
            // Сессия была заменена (вход с другого устройства)
            elseif ($user->session_id !== $currentSessionId) {
                Auth::logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->withErrors([
                    'email' => 'Выполнен вход с другого устройства. Текущая сессия закрыта.',
                ]);
            }
        }

        return $next($request);
    }
}