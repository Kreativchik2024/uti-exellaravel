<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z"/></svg>
            </span>
            <div>
                <h2>Лицензии модулей связи</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Партии: файл лицензии .dat + таблица серийников .xlsx · MANET Flasher скачивает их отсюда и ставит модулю лицензию его партии</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if(session('status'))
            <div class="ui-card px-4 py-3 text-sm" style="color: var(--ui-good)">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="ui-card px-4 py-3 text-sm" style="color: var(--ui-bad)">
                @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        <form method="POST" action="{{ route('comm-licenses.store') }}" enctype="multipart/form-data" class="ui-card p-4 space-y-3">
            @csrf
            <div class="font-semibold">Загрузить партию</div>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="text-sm">Лицензия (.dat)
                    <input type="file" name="dat" accept=".dat" class="block mt-1 text-sm">
                </label>
                <label class="text-sm">Таблица серийников (.xlsx)
                    <input type="file" name="xlsx" accept=".xlsx" class="block mt-1 text-sm">
                </label>
                <label class="text-sm">Название партии
                    <input name="batch" value="{{ old('batch') }}" maxlength="80" class="ui-input mt-1 w-full" placeholder="пусто — по имени таблицы">
                </label>
            </div>
            <p class="text-xs" style="color: var(--ui-muted)">Партия с тем же названием обновляется: новый файл заменяет старый того же вида. Лицензия и таблица должны быть из одной поставки — иначе модулю поставится чужая лицензия.</p>
            <button class="ui-btn ui-btn--primary">Загрузить</button>
        </form>

        <form method="GET" action="{{ route('comm-licenses.index') }}" class="flex flex-wrap items-center gap-2">
            <input name="sn" value="{{ $sn }}" class="ui-input font-mono" style="width: 360px" placeholder="S/N модуля (32 знака) — в какой партии">
            <button class="ui-btn ui-btn--ghost">Найти</button>
            @if($found !== null)
                <span class="text-sm" style="color: {{ $found ? 'var(--ui-good)' : 'var(--ui-bad)' }}">
                    {{ $found ? 'есть в: ' . implode(', ', $found) : 'нет ни в одной партии' }}
                </span>
            @endif
        </form>

        <div class="ui-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th>Партия</th><th>Лицензия</th><th>Таблица</th><th style="width: 110px">Серийников</th><th style="width: 140px">Обновлено</th><th style="width: 60px"></th></tr></thead>
                    <tbody>
                        @forelse($batches as $b)
                            <tr>
                                <td class="font-semibold">{{ $b['name'] }}</td>
                                <td>
                                    @if($b['dat'])
                                        <a href="{{ route('comm-licenses.download', [$b['name'], $b['dat']['file']]) }}" class="font-mono text-sm">{{ $b['dat']['file'] }}</a>
                                    @else<span style="color: var(--ui-bad)">нет</span>@endif
                                </td>
                                <td>
                                    @if($b['xlsx'])
                                        <a href="{{ route('comm-licenses.download', [$b['name'], $b['xlsx']['file']]) }}" class="text-sm">{{ $b['xlsx']['file'] }}</a>
                                    @else<span style="color: var(--ui-bad)">нет — модули этой партии не подберутся</span>@endif
                                </td>
                                <td class="tabular-nums">{{ $b['serials'] ?: '—' }}</td>
                                <td style="color: var(--ui-muted)">{{ $b['updated'] ? date('d.m.Y H:i', $b['updated']) : '' }}</td>
                                <td class="text-right">
                                    <form method="POST" action="{{ route('comm-licenses.destroy', $b['name']) }}" onsubmit="return confirm('Удалить партию {{ $b['name'] }}? Программы перестанут её получать.')">
                                        @csrf @method('DELETE')
                                        <button class="ui-btn ui-btn--ghost ui-btn--sm" style="color: var(--ui-bad)" aria-label="Удалить">✕</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-center py-6" style="color: var(--ui-muted)">Партий пока нет — загрузите лицензию и таблицу серийников</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-app-layout>
