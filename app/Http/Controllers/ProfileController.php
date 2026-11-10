<?php

namespace App\Http\Controllers;

use App\Rules\CleanText;
use App\Rules\NoEmoji;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function edit(Request $request)
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'name'  => ['required', 'string', 'max:255', new NoEmoji(), new CleanText()],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        $user->fill($data);
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();

        return redirect()->route('profile.edit')->with('ok', 'Профиль обновлён.');
    }

    /** Неверных вводов текущего ПИН до блокировки смены на PIN_LOCK_SECONDS. */
    private const PIN_MAX_ATTEMPTS = 5;
    private const PIN_LOCK_SECONDS = 900;

    public function updatePin(Request $request)
    {
        $user = $request->user();
        $hadPin = $user->hasPin();

        $data = $request->validate([
            // Сменить уже заданный ПИН можно только зная текущий
            'current_pin' => [$hadPin ? 'required' : 'nullable', 'string'],
            'pin'         => ['required', 'digits_between:4,6'],
            'pin_confirm' => ['required', 'same:pin'],
        ], [
            'current_pin.required' => 'Введите текущий ПИН.',
            'pin.required'         => 'Введите ПИН.',
            'pin.digits_between'   => 'ПИН — 4–6 цифр.',
            'pin_confirm.required' => 'Повторите ПИН.',
            'pin_confirm.same'     => 'ПИН и подтверждение не совпадают.',
        ]);

        if ($hadPin) {
            $key = 'pin-change:' . $user->id;
            if (RateLimiter::tooManyAttempts($key, self::PIN_MAX_ATTEMPTS)) {
                $minutes = (int) ceil(RateLimiter::availableIn($key) / 60);
                throw ValidationException::withMessages(['current_pin' => "Слишком много неверных попыток. Попробуйте через {$minutes} мин. или обратитесь к администратору."]);
            }
            if (! $user->checkPin((string) $data['current_pin'])) {
                RateLimiter::hit($key, self::PIN_LOCK_SECONDS);
                throw ValidationException::withMessages(['current_pin' => 'Неверный текущий ПИН.']);
            }
            RateLimiter::clear($key);
        }

        $user->setPin($data['pin']);

        return back()->with('ok', $hadPin ? 'ПИН изменён.' : 'ПИН установлен.');
    }

    public function destroy(Request $request)
    {
        // Удаление учётки — только с подтверждением текущим паролем
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}