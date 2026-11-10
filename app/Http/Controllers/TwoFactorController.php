<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureTwoFactor;
use App\Models\AuditLog;
use App\Services\Totp;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/** Настройка и ввод одноразового кода из приложения на телефоне. */
class TwoFactorController extends Controller
{
    private const SETUP_KEY = 'two_factor_setup_secret';

    /** Страница защиты: QR-код для настройки или состояние (включена). */
    public function setup(Request $r)
    {
        $user = $r->user();

        if ($user->hasTwoFactor()) {
            return view('two-factor.setup', ['user' => $user, 'enabled' => true, 'codes' => session('recovery_codes')]);
        }

        $secret = $r->session()->get(self::SETUP_KEY) ?: Totp::generateSecret();
        $r->session()->put(self::SETUP_KEY, $secret);

        return view('two-factor.setup', [
            'user'    => $user,
            'enabled' => false,
            'secret'  => trim(chunk_split($secret, 4, ' ')),
            'qr'      => Totp::qrSvg(Totp::uri($secret, $user->email)),
        ]);
    }

    public function confirm(Request $r)
    {
        $r->validate(['code' => ['required', 'string', 'max:10']]);
        $user = $r->user();
        $secret = $r->session()->get(self::SETUP_KEY);
        abort_unless($secret, 419);

        if (! Totp::verify($secret, $r->input('code'), $user->id)) {
            throw ValidationException::withMessages(['code' => 'Код не подошёл. Проверьте, что время на телефоне и сервере совпадает, и введите новый код.']);
        }

        $codes = Totp::recoveryCodes();
        $user->forceFill([
            'two_factor_secret'         => $secret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at'   => now(),
        ])->save();
        $r->session()->forget(self::SETUP_KEY);
        $r->session()->put(EnsureTwoFactor::SESSION_KEY, $user->id);
        AuditLog::write('profile', 'two_factor', 'Включил вход с кодом из приложения', ['subject_type' => 'user', 'subject_id' => $user->id, 'section_id' => null]);

        return redirect()->route('two-factor.setup')->with('recovery_codes', $codes);
    }

    public function challenge(Request $r)
    {
        if (! $r->user()->hasTwoFactor()) return redirect()->route('two-factor.setup');

        return view('two-factor.challenge');
    }

    public function verify(Request $r)
    {
        $r->validate(['code' => ['required', 'string', 'max:20']]);
        $user = $r->user();
        abort_unless($user->hasTwoFactor(), 404);

        $key = 'two-factor:' . $user->id;
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => 'Слишком много попыток. Подождите ' . RateLimiter::availableIn($key) . ' с.']);
        }

        $input = trim((string) $r->input('code'));
        $ok = Totp::verify($user->two_factor_secret, $input, $user->id);
        $byRecovery = false;

        if (! $ok) {
            // Резервный код — одноразовый
            $codes = (array) $user->two_factor_recovery_codes;
            $idx = array_search(strtoupper($input), $codes, true);
            if ($idx !== false) {
                unset($codes[$idx]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();
                $ok = $byRecovery = true;
            }
        }

        if (! $ok) {
            RateLimiter::hit($key, 60);
            AuditLog::write('auth', 'login_failed', 'Неверный код из приложения: ' . $user->email, ['section_id' => null]);
            throw ValidationException::withMessages(['code' => 'Неверный код.']);
        }

        RateLimiter::clear($key);
        $r->session()->regenerate();
        $r->session()->put(EnsureTwoFactor::SESSION_KEY, $user->id);
        if ($byRecovery) {
            AuditLog::write('auth', 'two_factor', 'Вход по резервному коду (осталось ' . count($user->two_factor_recovery_codes) . ')', ['section_id' => null]);
        }

        return redirect()->intended(route('dashboard'))
            ->with('status', $byRecovery ? 'Использован резервный код. Осталось: ' . count($user->two_factor_recovery_codes) . '.' : null);
    }

    /** Новые резервные коды (старые перестают работать). */
    public function regenerate(Request $r)
    {
        $r->validate(['password' => ['required', 'current_password']]);
        $codes = Totp::recoveryCodes();
        $r->user()->forceFill(['two_factor_recovery_codes' => $codes])->save();
        AuditLog::write('profile', 'two_factor', 'Новые резервные коды', ['subject_type' => 'user', 'subject_id' => $r->user()->id, 'section_id' => null]);

        return redirect()->route('two-factor.setup')->with('recovery_codes', $codes);
    }

    /** Отключить (не для ролей, где защита обязательна). */
    public function disable(Request $r)
    {
        $r->validate(['password' => ['required', 'current_password']]);
        $user = $r->user();
        abort_if($user->requiresTwoFactor(), 403, 'Для вашей роли защита обязательна');

        $user->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        AuditLog::write('profile', 'two_factor', 'Отключил вход с кодом из приложения', ['subject_type' => 'user', 'subject_id' => $user->id, 'section_id' => null]);

        return redirect()->route('profile.edit')->with('ok', 'Вход с кодом отключён.');
    }
}
