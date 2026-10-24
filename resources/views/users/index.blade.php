<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Пользователи
        </h2>
    </x-slot>

    <div class="py-8 max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-4">

        @if(session('ok'))
            <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('ok') }}</div>
        @endif
        @if($errors->any())
            <div class="bg-red-100 text-red-800 p-3 rounded">
                @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        {{-- Фильтры --}}
        <form method="GET" class="bg-white p-4 rounded shadow flex gap-3 flex-wrap">
            <input name="q" value="{{ request('q') }}" placeholder="Имя или email" class="border rounded px-3 py-1">
            <select name="role" class="border rounded px-3 py-1">
                <option value="">Все роли</option>
                @foreach(['admin'=>'Админ','master'=>'Мастер','executor'=>'Исполнитель'] as $k=>$v)
                    <option value="{{ $k }}" @selected(request('role')===$k)>{{ $v }}</option>
                @endforeach
            </select>
            <select name="status" class="border rounded px-3 py-1">
                <option value="">Все статусы</option>
                <option value="active" @selected(request('status')==='active')>Активные</option>
                <option value="pending" @selected(request('status')==='pending')>Ожидают</option>
            </select>
            <button class="bg-blue-600 text-white px-4 py-1 rounded">Фильтр</button>
        </form>

        {{-- Таблица --}}
        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                <tr>
                    <th class="p-2 text-left">ID</th>
                    <th class="p-2 text-left">Имя</th>
                    <th class="p-2 text-left">Email</th>
                    <th class="p-2 text-left">Роль</th>
                    <th class="p-2 text-left">Статус</th>
                    <th class="p-2 text-left">Вход</th>
                    <th class="p-2 text-left">Действия</th>
                </tr>
                </thead>
                <tbody>
                @foreach($users as $u)
                    <tr class="border-t">
                        <td class="p-2">{{ $u->id }}</td>
                        <td class="p-2">{{ $u->name }}</td>
                        <td class="p-2">{{ $u->email }}</td>
                        <td class="p-2">
                            <form method="POST" action="{{ route('users.role', $u) }}">
                                @csrf @method('PATCH')
                                <select name="role" onchange="this.form.submit()" class="border rounded px-2 py-0.5">
                                    @foreach(['admin'=>'Админ','master'=>'Мастер','executor'=>'Исполнитель'] as $k=>$v)
                                        <option value="{{ $k }}" @selected($u->role===$k)>{{ $v }}</option>
                                    @endforeach
                                </select>
                            </form>
                        </td>
                        <td class="p-2">
                            @if($u->is_active)
                                <span class="text-green-600">Активен</span>
                            @else
                                <span class="text-amber-600">Ожидает</span>
                            @endif
                        </td>
                        <td class="p-2 text-xs text-gray-500">
                            {{ $u->last_login_at?->format('d.m.Y H:i') ?? '—' }}
                        </td>
                        <td class="p-2 space-x-2">
                            @if(! $u->is_active)
                                <form method="POST" action="{{ route('users.approve', $u) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <button class="text-green-700 underline">Активировать</button>
                                </form>
                            @else
                                <form method="POST" action="{{ route('users.deactivate', $u) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <button class="text-amber-700 underline">Деактивировать</button>
                                </form>
                            @endif

                            {{-- Сброс пароля через prompt --}}
                            <form method="POST" action="{{ route('users.password', $u) }}"
                                  onsubmit="return confirm('Сбросить пароль?')" class="inline">
                                @csrf @method('PATCH')
                                <input type="password" name="password" required placeholder="новый пароль"
                                       class="border rounded px-2 py-0.5 text-xs w-32">
                                <input type="password" name="password_confirmation" required placeholder="повтор"
                                       class="border rounded px-2 py-0.5 text-xs w-32">
                                <button class="text-blue-700 underline">Сбросить</button>
                            </form>

                            @if($u->id !== auth()->id())
                                <form method="POST" action="{{ route('users.destroy', $u) }}"
                                      onsubmit="return confirm('Удалить пользователя?')" class="inline">
                                    @csrf @method('DELETE')
                                    <button class="text-red-700 underline">Удалить</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div>{{ $users->links() }}</div>
    </div>
</x-app-layout>