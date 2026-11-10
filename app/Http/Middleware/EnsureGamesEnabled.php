<?php

namespace App\Http\Middleware;

use App\Models\Setting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Игры выключены админом — раздел недоступен (сам админ может зайти, чтобы включить). */
class EnsureGamesEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Setting::gamesEnabled() || $request->user()?->isAdmin()) {
            return $next($request);
        }

        $message = 'Игры выключены администратором.';

        return $request->expectsJson()
            ? response()->json(['message' => $message], 403)
            : redirect()->route('dashboard')->withErrors(['general' => $message]);
    }
}
