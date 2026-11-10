<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Http\Controllers\ConsentController;
use App\Rules\CleanText;
use App\Rules\NoEmoji;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'name'     => ['required', 'string', 'max:255', new NoEmoji(), new CleanText()],
            'email'    => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'accept_rules' => ['accepted'],
        ], ['accept_rules.accepted' => 'Без согласия с правилами и на обработку персональных данных регистрация невозможна.']);

        $user = (new User)->forceFill([
            'name'      => $request->name,
            'email'     => $request->email,
            'password'  => Hash::make($request->password),
            'role'      => 'executor',
            'is_active' => false,
        ]);
        $user->save();
        ConsentController::record($user, $request);

        // Письмо-подтверждение email для внутренней системы не нужно.
        // event(new Registered($user));

        return redirect()->route('login')
            ->with('status', 'Регистрация принята. Ожидайте подтверждения администратора.');
    }
}