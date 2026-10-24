<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">Категории прошивок</h2>
            <a href="{{ route('firmware.index') }}" class="text-blue-600 underline">← К прошивкам</a>
        </div>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8 space-y-4">
        @if(session('ok'))
            <div class="bg-green-100 text-green-800 p-3 rounded">{{ session('ok') }}</div>
        @endif
        @if($errors->any())
            <div class="bg-red-100 text-red-800 p-3 rounded">
                @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('firmware.categories.store') }}"
              class="bg-white p-4 rounded shadow grid grid-cols-4 gap-2">
            @csrf
            <input name="title" placeholder="Название" required class="border rounded px-2 py-1 col-span-2">
            <input name="slug" placeholder="slug (латиница, опц.)" class="border rounded px-2 py-1">
            <button class="bg-blue-600 text-white px-4 py-1 rounded">Добавить</button>
        </form>

        <div class="bg-white rounded shadow">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                <tr>
                    <th class="p-2 text-left">Slug</th>
                    <th class="p-2 text-left">Название</th>
                    <th class="p-2 text-left">Системная</th>
                    <th class="p-2 text-left">Версий</th>
                    <th class="p-2 text-left">Действия</th>
                </tr>
                </thead>
                <tbody>
                @foreach($categories as $c)
                    <tr class="border-t">
                        <td class="p-2 font-mono">{{ $c->slug }}</td>
                        <td class="p-2">{{ $c->title }}</td>
                        <td class="p-2">{{ $c->is_system ? 'да' : 'нет' }}</td>
                        <td class="p-2">{{ $c->versions()->count() }}</td>
                        <td class="p-2">
                            @unless($c->is_system)
                                <form method="POST" action="{{ route('firmware.categories.destroy', $c) }}"
                                      class="inline" onsubmit="return confirm('Удалить категорию?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 underline">Удалить</button>
                                </form>
                            @endunless
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</x-app-layout>