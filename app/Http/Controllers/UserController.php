<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::query()
            ->when($request->filled('q'), fn($q) => $q
                ->where('name', 'like', "%{$request->q}%")
                ->orWhere('email', 'like', "%{$request->q}%"))
            ->when($request->filled('role'), fn($q) => $q->where('role', $request->role))
            ->when($request->filled('status'), function ($q) use ($request) {
                $request->status === 'active'
                    ? $q->where('is_active', true)
                    : $q->where('is_active', false);
            })
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->paginate(30)
            ->withQueryString();

        return view('users.index', compact('users'));
    }

    public function updateRole(Request $request, User $user)
    {
        $data = $request->validate([
            'role' => ['required', Rule::in(['admin', 'master', 'executor'])],
        ]);

        if ($user->id === auth()->id() && $data['role'] !== 'admin') {
            return back()->withErrors(['role' => 'Нельзя снять роль админа с самого себя.']);
        }

        $user->update(['role' => $data['role']]);
        return back()->with('ok', "Роль пользователя {$user->name} изменена на {$data['role']}");
    }

    public function approve(User $user)
    {
        $user->update([
            'is_active'   => true,
            'approved_by' => auth()->id(),
            'approved_at' => now(),
        ]);
        return back()->with('ok', "Пользователь {$user->name} активирован");
    }

    public function deactivate(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['general' => 'Нельзя деактивировать самого себя.']);
        }
        $user->update(['is_active' => false]);
        return back()->with('ok', "Пользователь {$user->name} деактивирован");
    }

    public function resetPassword(Request $request, User $user)
    {
        $data = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)],
        ]);

        $user->update(['password' => Hash::make($data['password'])]);
        return back()->with('ok', "Пароль пользователя {$user->name} изменён");
    }

    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return back()->withErrors(['general' => 'Нельзя удалить самого себя.']);
        }
        $user->delete();
        return back()->with('ok', 'Пользователь удалён');
    }
}