<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    public function create(): View
    {
        return view('auth.login');
    }

    public function store(LoginRequest $request): RedirectResponse
    {
        $request->authenticate();

        if (! auth()->user()->is_active) {
            auth()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                'email' => 'Аккаунт ожидает подтверждения администратором.',
            ])->onlyInput('email');
        }

        // regenerate() создаёт новый ID сессии
        $request->session()->regenerate();

        $user = auth()->user();

        // Single session: для executor запоминаем текущую сессию.
        // При входе с другого устройства EnsureSingleSession() разлогинит старую.
        if ($user->isExecutor()) {
            $user->forceFill(['session_id' => session()->getId()])->saveQuietly();
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return redirect()->intended(route('dashboard', absolute: false));
    }

    public function destroy(Request $request): RedirectResponse
    {
        // Single session: очищаем session_id, иначе следующий вход
        // с того же устройства middleware посчитает «чужим».
        $user = $request->user();
        if ($user && $user->isExecutor()) {
            $user->forceFill(['session_id' => null])->saveQuietly();
        }

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}