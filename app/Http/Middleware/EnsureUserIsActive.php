<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Выкидывает из системы пользователя, которого деактивировали
 * уже после входа (is_active проверяется не только при логине).
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && ! $user->is_active) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Учётная запись деактивирована.'], 401);
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Учётная запись деактивирована администратором.',
            ]);
        }

        return $next($request);
    }
}
