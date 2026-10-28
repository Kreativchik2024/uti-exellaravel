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
            $request->routeIs('logout') ||
            $request->routeIs('profile.*') ||
            $request->routeIs('password.*')
        ) {
            return $next($request);
        }

        if (!session()->has('workstation_workers')) {
            return redirect()->route('workstation.show', ['intended' => $request->fullUrl()]);
        }

        return $next($request);
    }
}