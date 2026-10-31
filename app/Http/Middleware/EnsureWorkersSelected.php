<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureWorkersSelected
{
    public function handle(Request $request, Closure $next): Response
    {
        // Эти маршруты доступны без выбора исполнителей
        if (
            $request->routeIs('workstation.*') ||
            $request->routeIs('news.latest') ||
            $request->routeIs('logout') ||
            $request->routeIs('profile.*') ||
            $request->routeIs('password.*')
        ) {
            return $next($request);
        }

        // Пользователь явно нажал «Пропустить (без исполнителей)» — пропускаем
        if (session('workstation_skipped', false)) {
            return $next($request);
        }

        // Ключ есть в сессии: имена или [] (нажал «Продолжить» без выбора)
        if (session()->has('workstation_workers')) {
            return $next($request);
        }

        return redirect()->route('workstation.show', ['intended' => $request->fullUrl()]);
    }
}