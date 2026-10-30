<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center">
            <h2 class="font-semibold text-xl text-gray-800">Прошивки и инструкции</h2>
            <div class="flex gap-2">
                @if(auth()->user()->canManageContent())
                    <a href="{{ route('firmware.create') }}" class="bg-blue-600 text-white px-4 py-2 rounded">+ Загрузить</a>
                @endif
                @if(auth()->user()->isAdmin())
                    <a href="{{ route('firmware.categories.index') }}" class="bg-gray-200 text-gray-800 px-4 py-2 rounded">Категории</a>
                @endif
            </div>
        </div>
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

        <div class="flex gap-2 border-b">
            @foreach(['actual'=>'Актуальные','deprecated'=>'Устаревшие','archive'=>'Архив'] as $key => $label)
                <a href="{{ route('firmware.index', ['tab'=>$key, 'category'=>$cat]) }}"
                   class="px-4 py-2 -mb-px border-b-2 {{ $tab === $key ? 'border-blue-600 text-blue-700 font-semibold' : 'border-transparent text-gray-600' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>

        <form method="GET" class="bg-white p-3 rounded shadow flex gap-2 flex-wrap">
            <input type="hidden" name="tab" value="{{ $tab }}">
            <select name="category" class="border rounded px-2 py-1">
                <option value="">Все категории</option>
                @foreach($categories as $c)
                    <option value="{{ $c->slug }}" @selected($cat === $c->slug)>{{ $c->title }}</option>
                @endforeach
            </select>
            <input name="q" value="{{ $q }}" placeholder="Поиск по версии / описанию"
                   class="border rounded px-3 py-1 flex-1 min-w-48">
            <button class="bg-gray-800 text-white px-4 py-1 rounded">Фильтр</button>
        </form>

        <div class="bg-white rounded shadow overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-100">
                <tr>
                    <th class="p-2 text-left">Категория</th>
                    <th class="p-2 text-left">Версия</th>
                    <th class="p-2 text-left">Размер</th>
                    <th class="p-2 text-left">SHA-256</th>
                    <th class="p-2 text-left">Загрузил</th>
                    <th class="p-2 text-left">Дата</th>
                    <th class="p-2 text-left">Действия</th>
                </tr>
                </thead>
                <tbody>
                @forelse($versions as $v)
                    <tr class="border-t">
                        <td class="p-2">{{ $v->category->title }}</td>
                        <td class="p-2 font-mono">{{ $v->version }}</td>
                        <td class="p-2">{{ $v->humanSize() }}</td>
                        <td class="p-2 font-mono text-xs" title="{{ $v->sha256 }}">
                            {{ $v->sha256 ? substr($v->sha256, 0, 12).'…' : '—' }}
                        </td>
                        <td class="p-2">{{ $v->uploader?->name ?? '—' }}</td>
                        <td class="p-2 text-xs">{{ $v->uploaded_at?->format('d.m.Y H:i') }}</td>
                        <td class="p-2 space-x-2 whitespace-nowrap">
                            <a href="{{ route('firmware.download', $v) }}" class="text-blue-600 underline">Скачать</a>

                            @can('update', $v)
                                <form method="POST" action="{{ route('firmware.status', $v) }}" class="inline">
                                    @csrf @method('PATCH')
                                    <select name="status" onchange="this.form.submit()"
                                            class="border rounded px-1 py-0.5 text-xs">
                                        @foreach(['actual'=>'Актуальная','deprecated'=>'Устаревшая','archive'=>'Архив'] as $k=>$lbl)
                                            <option value="{{ $k }}" @selected($v->status === $k)>{{ $lbl }}</option>
                                        @endforeach
                                    </select>
                                </form>
                            @endcan

                            @can('delete', $v)
                                <form method="POST" action="{{ route('firmware.destroy', $v) }}" class="inline"
                                      onsubmit="return confirm('Удалить прошивку и файл?')">
                                    @csrf @method('DELETE')
                                    <button class="text-red-600 underline">Удалить</button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="p-4 text-center text-gray-500">Нет данных</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div>{{ $versions->links() }}</div>
    </div>
</x-app-layout>