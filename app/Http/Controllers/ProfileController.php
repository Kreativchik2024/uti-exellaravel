<?php

namespace App\Http\Controllers;

use App\Rules\NoEmoji;
use Illuminate\Http\Request;
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
            'name'  => ['required', 'string', 'max:255', new NoEmoji()],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
        ]);

        $user->fill($data);
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }
        $user->save();

        return redirect()->route('profile.edit')->with('ok', 'Профиль обновлён.');
    }

    public function updatePin(Request $request)
    {
        $user = $request->user();

        $data = $request->validate([
            'pin'         => ['required', 'digits_between:4,6'],
            'pin_confirm' => ['required', 'same:pin'],
        ], [
            'pin.required'         => 'Введите ПИН.',
            'pin.digits_between'   => 'ПИН — 4–6 цифр.',
            'pin_confirm.required' => 'Повторите ПИН.',
            'pin_confirm.same'     => 'ПИН и подтверждение не совпадают.',
        ]);

        $user->setPin($data['pin']);

        return back()->with('ok', 'ПИН установлен.');
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