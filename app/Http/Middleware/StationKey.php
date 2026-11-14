<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доступ программ на рабочих станциях (флешер камер, считыватель) к /api/station/*.
 * Ключ — STATION_API_KEY в .env; программа передаёт его в заголовке X-Station-Key.
 * Пустой ключ в .env — API выключен.
 */
class StationKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = (string) config('services.stations.key');

        if ($key === '') {
            return response()->json(['ok' => false, 'error' => 'API станций выключен (STATION_API_KEY не задан на сервере).'], 403);
        }
        if (!hash_equals($key, (string) $request->header('X-Station-Key'))) {
            return response()->json(['ok' => false, 'error' => 'Неверный ключ станции.'], 401);
        }

        return $next($request);
    }
}
