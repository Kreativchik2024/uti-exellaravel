<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
            </span>
            <div>
                <h2>Коды неисправностей</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Выпадают в поле «Код неисправности» во всех вкладках · всего {{ $total }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if(session('ok'))
            <div class="ui-card px-4 py-3 text-sm font-medium" role="status" style="background: var(--ui-good-soft); color: var(--ui-good); border-color: transparent">{{ session('ok') }}</div>
        @endif
        @if($errors->any())
            <div class="ui-card px-4 py-3 text-sm font-medium" role="alert" style="background: var(--ui-bad-soft); color: var(--ui-bad); border-color: transparent">{{ $errors->first() }}</div>
        @endif

        <div class="grid gap-4 md:grid-cols-2">
            {{-- Загрузка файла --}}
            <form method="POST" action="{{ route('fault-codes.import') }}" enctype="multipart/form-data" class="ui-card p-4 space-y-3">
                @csrf
                <h3 class="font-semibold" style="color: var(--ui-text)">Загрузить из файла</h3>
                <p class="text-sm" style="color: var(--ui-muted)">
                    Excel (.xlsx, .xls) или CSV. Две колонки: <strong>Код</strong> и <strong>Описание</strong> — с заголовком или без
                    (тогда код — в первой колонке). Уже существующие коды обновятся, новые добавятся.
                </p>
                <input type="file" name="file" required accept=".xlsx,.xls,.csv,.txt" class="block w-full text-sm">
                <label class="block">
                    <span class="ui-label">Для участка</span>
                    <select name="section_id" class="ui-input w-full">
                        <option value="">Для всех участков</option>
                        @foreach($sections as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </select>
                </label>
                <div class="flex flex-wrap gap-2">
                    <button class="ui-btn ui-btn--primary">Загрузить</button>
                    <a href="{{ route('fault-codes.template') }}" class="ui-btn ui-btn--ghost">Скачать шаблон</a>
                </div>
            </form>

            {{-- Добавить один --}}
            <form method="POST" action="{{ route('fault-codes.store') }}" class="ui-card p-4 space-y-3">
                @csrf
                <h3 class="font-semibold" style="color: var(--ui-text)">Добавить код</h3>
                <label class="block"><span class="ui-label">Код</span><input name="code" required maxlength="100" class="ui-input w-full" placeholder="E01"></label>
                <label class="block"><span class="ui-label">Описание</span><input name="description" maxlength="255" class="ui-input w-full" placeholder="Нет связи с модулем"></label>
                <label class="block">
                    <span class="ui-label">Для участка</span>
                    <select name="section_id" class="ui-input w-full">
                        <option value="">Для всех участков</option>
                        @foreach($sections as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach
                    </select>
                </label>
                <button class="ui-btn ui-btn--primary">Добавить</button>
            </form>
        </div>

        <form method="GET" class="ui-card p-3 flex gap-2">
            <input type="search" name="q" value="{{ $q }}" class="ui-input flex-1" placeholder="Поиск по коду или описанию">
            <button class="ui-btn ui-btn--ghost">Найти</button>
        </form>

        <div class="ui-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th style="width: 140px">Код</th><th>Описание</th><th style="width: 170px">Участок</th><th style="width: 90px">Активен</th><th style="width: 150px"></th></tr></thead>
                    <tbody>
                        @forelse($codes as $c)
                            <tr x-data="{ edit: false }">
                                <td class="font-mono font-semibold" :class="!edit || 'hidden'">{{ $c->code }}</td>
                                <td :class="!edit || 'hidden'">{{ $c->description ?: '—' }}</td>
                                <td :class="!edit || 'hidden'" style="color: var(--ui-muted)">{{ $c->section?->name ?? 'все' }}</td>
                                <td :class="!edit || 'hidden'">{{ $c->is_active ? 'да' : 'нет' }}</td>
                                <td :class="!edit || 'hidden'" class="text-right whitespace-nowrap">
                                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="edit = true">Изменить</button>
                                    <form method="POST" action="{{ route('fault-codes.destroy', $c) }}" class="inline" onsubmit="return confirm('Удалить код {{ $c->code }}?')">
                                        @csrf @method('DELETE')
                                        <button class="ui-btn ui-btn--ghost ui-btn--sm" style="color: var(--ui-bad)" aria-label="Удалить">✕</button>
                                    </form>
                                </td>
                                <td x-show="edit" x-cloak colspan="5">
                                    <form method="POST" action="{{ route('fault-codes.update', $c) }}" class="flex flex-wrap gap-2 items-center">
                                        @csrf @method('PATCH')
                                        <input name="code" value="{{ $c->code }}" required maxlength="100" class="ui-input font-mono" style="width: 120px">
                                        <input name="description" value="{{ $c->description }}" maxlength="255" class="ui-input flex-1" style="min-width: 200px">
                                        <select name="section_id" class="ui-input">
                                            <option value="">Все участки</option>
                                            @foreach($sections as $s)<option value="{{ $s->id }}" @selected($c->section_id === $s->id)>{{ $s->name }}</option>@endforeach
                                        </select>
                                        <label class="text-sm flex items-center gap-1"><input type="checkbox" name="is_active" value="1" @checked($c->is_active)> активен</label>
                                        <button class="ui-btn ui-btn--primary ui-btn--sm">Сохранить</button>
                                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="edit = false">Отмена</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center py-8" style="color: var(--ui-muted)">Кодов пока нет — загрузите файл или добавьте вручную</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ $codes->links() }}
    </div>
</x-app-layout>
