<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z"/></svg>
            </span>
            <div>
                <h2>Коды неисправностей</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">В формах учёта выпадают коды подгрупп, привязанных к вкладке, и общие · всего {{ $total }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if(session('ok'))
            <div class="ui-card px-4 py-3 text-sm font-medium" role="status" style="background: var(--ui-good-soft); color: var(--ui-good); border-color: transparent">{{ session('ok') }}</div>
        @endif
        @if($errors->any())
            <div class="ui-card px-4 py-3 text-sm font-medium" role="alert" style="background: var(--ui-bad-soft); color: var(--ui-bad); border-color: transparent">{{ $errors->first() }}</div>
        @endif
        @if($toReview)
            <a href="{{ route('fault-codes.index', ['review' => 1]) }}" class="ui-card px-4 py-3 text-sm font-medium flex items-center gap-2" style="background: rgba(245,158,11,.12); border-color: transparent; color: #b45309">
                Новых кодов, добавленных автоматически: {{ $toReview }} — проверьте описание или объедините с существующими →
            </a>
        @endif

        {{-- Подгруппы и разделы --}}
        <section class="ui-card overflow-hidden" x-data="{ adding: false }">
            <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-3 pb-2">
                <h3 class="font-semibold" style="color: var(--ui-text)">Подгруппы и разделы</h3>
                <span class="flex flex-wrap gap-2">
                    <a href="{{ route('fault-codes.export') }}" class="ui-btn ui-btn--ghost ui-btn--sm">Выгрузить в Excel</a>
                    @if($total)
                        <form method="POST" action="{{ route('fault-codes.wipe') }}"
                              onsubmit="const w = prompt('Стереть все {{ $total }} кодов и уровни справочника?\n\nВ записях учёта коды останутся и после загрузки новой таблицы привяжутся к тем же или похожим кодам.\nСначала можно выгрузить справочник в Excel.\n\nДля подтверждения введите: СТЕРЕТЬ'); if (w === null) return false; this.confirm.value = w.trim().toUpperCase(); return true;">
                            @csrf
                            <input type="hidden" name="confirm">
                            <button class="ui-btn ui-btn--ghost ui-btn--sm" style="color: var(--ui-bad)">Стереть все коды</button>
                        </form>
                    @endif
                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="adding = !adding">+ Подгруппа / раздел</button>
                </span>
            </div>
            <p class="px-4 pb-2 text-xs" style="color: var(--ui-muted)">
                Коды подгруппы выпадают только во вкладках, отмеченных у неё. Новая неисправность, вписанная во вкладке, попадает в подходящий раздел и продолжает его нумерацию: после C(3).3.10 — C(3).3.11.
            </p>

            <form x-show="adding" x-cloak method="POST" action="{{ route('fault-codes.groups.store') }}" class="px-4 pb-4">
                @csrf
                @include('fault-codes._group_fields', ['g' => null])
                <button class="ui-btn ui-btn--primary ui-btn--sm mt-2">Создать</button>
            </form>

            @forelse($groups as $g)
                <div class="ui-fc-group-row {{ $g->parent_id ? 'is-child' : '' }}" style="padding-left: {{ 1 + ($g->depth() - 1) * 1.6 }}rem" x-data="{ edit: false }">
                    <div class="flex items-center gap-3 flex-wrap">
                        <span class="ui-fc-code">{{ $g->prefix }}</span>
                        <span class="text-xs font-mono" style="color: var(--ui-muted)">Р{{ $g->depth() }}</span>
                        <a href="{{ route('fault-codes.index', ['group' => $g->id]) }}" class="font-semibold" style="color: var(--ui-text)">{{ $g->name }}</a>
                        @if($g->codes_count)<span class="text-xs" style="color: var(--ui-muted)">{{ $g->codes_count }} код.</span>@endif
                        <span class="text-xs" style="color: var(--ui-muted)">
                            @if($g->sheets) вкладки: {{ collect($g->sheets)->map(fn ($s) => $sheets[$s] ?? $s)->implode(', ') }}
                            @elseif(! $g->parent_id && ! $g->effectiveSheets()) <span style="color:#b45309">не привязан к вкладкам — коды не выпадают</span>
                            @endif
                        </span>
                        <span class="ml-auto flex gap-1">
                            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="edit = !edit">Изменить</button>
                            <form method="POST" action="{{ route('fault-codes.groups.destroy', $g) }}" onsubmit="return confirm('Удалить? Коды станут общими, разделы внутри подгруппы удалятся.')">
                                @csrf @method('DELETE')
                                <button class="ui-btn ui-btn--ghost ui-btn--sm" style="color: var(--ui-bad)">✕</button>
                            </form>
                        </span>
                    </div>
                    <form x-show="edit" x-cloak method="POST" action="{{ route('fault-codes.groups.update', $g) }}" class="mt-3">
                        @csrf @method('PATCH')
                        @include('fault-codes._group_fields', ['g' => $g])
                        <button class="ui-btn ui-btn--primary ui-btn--sm mt-2">Сохранить</button>
                    </form>
                </div>
            @empty
                <p class="px-4 pb-4 text-sm" style="color: var(--ui-muted)">Подгрупп пока нет. Все коды — общие и выпадают во всех вкладках. Загрузите таблицу кодов — подгруппы и разделы создадутся по ней.</p>
            @endforelse
        </section>

        <div class="grid gap-4 md:grid-cols-2">
            {{-- Загрузка файла --}}
            <form method="POST" action="{{ route('fault-codes.import') }}" enctype="multipart/form-data" class="ui-card p-4 space-y-3">
                @csrf
                <h3 class="font-semibold" style="color: var(--ui-text)">Загрузить из файла</h3>
                <p class="text-sm" style="color: var(--ui-muted)">
                    Excel или CSV, как таблица предприятия: уровни <strong>Р1</strong>, <strong>Р2</strong>, <strong>Р3</strong>, <strong>Описание</strong>,
                    <strong>общий вид</strong> (это код), <strong>Разъяснение</strong>. «СМ Р3», «СМ ОПИСАНИЕ», «Нет» — уровня нет.
                    Уровни создаются сами, вкладки подбираются по названию.
                    Можно загрузить и журнал анализа отказов («Код», «Код первопричины», «Формализованный дефект») —
                    коды соберутся сами: SYS_CAM_N.HW-CONN-PIN.1.
                    Выгруженный файл можно поправить и загрузить обратно.
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
                <label class="block"><span class="ui-label">Описание неисправности</span><input name="description" maxlength="255" class="ui-input w-full" placeholder="Неисправен компас" required></label>
                <label class="block"><span class="ui-label">Разъяснение (необязательно)</span><input name="hint" maxlength="1000" class="ui-input w-full" placeholder="Как понять, что это именно эта неисправность"></label>
                <div class="grid grid-cols-2 gap-3">
                    <label class="block">
                        <span class="ui-label">Подгруппа / раздел</span>
                        <select name="group_id" class="ui-input w-full">
                            <option value="">Общий (без подгруппы)</option>
                            @foreach($groups as $g)<option value="{{ $g->id }}">{{ $g->label() }} ({{ $g->prefix }})</option>@endforeach
                        </select>
                    </label>
                    <label class="block"><span class="ui-label">Код (необязательно)</span><input name="code" maxlength="100" class="ui-input w-full" placeholder="подставится сам"></label>
                </div>
                <p class="text-xs" style="color: var(--ui-muted)">Если выбрать раздел и не указывать код — подставится следующий по номеру: C(3).3.11.</p>
                <button class="ui-btn ui-btn--primary">Добавить</button>
            </form>
        </div>

        {{-- Фильтры --}}
        <form method="GET" class="ui-card p-3 flex flex-wrap gap-2 items-center">
            <input type="search" name="q" value="{{ $q }}" class="ui-input flex-1" style="min-width: 200px" placeholder="Поиск по коду или описанию">
            <select name="group" class="ui-input">
                <option value="">Все подгруппы</option>
                <option value="none" @selected($group === 'none')>Общие (без подгруппы)</option>
                @foreach($groups as $g)<option value="{{ $g->id }}" @selected((string) $group === (string) $g->id)>{{ $g->label() }}</option>@endforeach
            </select>
            <label class="text-sm flex items-center gap-1.5"><input type="checkbox" name="review" value="1" @checked($review)> только новые</label>
            <button class="ui-btn ui-btn--ghost">Показать</button>
        </form>

        <div class="ui-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead><tr><th style="width: 120px">Код</th><th>Описание</th><th style="width: 200px">Подгруппа · раздел</th><th style="width: 80px">Активен</th><th style="width: 210px"></th></tr></thead>
                    <tbody>
                        @forelse($codes as $c)
                            <tr x-data="{ edit: false, merge: false }">
                                <td class="font-mono font-semibold" x-show="!edit">{{ $c->code }}</td>
                                <td x-show="!edit">
                                    {{ $c->description ?: '—' }}
                                    @if($c->hint)<div class="text-xs mt-0.5" style="color: var(--ui-muted)">{{ $c->hint }}</div>@endif
                                    @if($c->needs_review)
                                        <span class="ui-badge-warn" title="Добавлен автоматически при вводе записи{{ $c->creator ? ' — ' . $c->creator->name : '' }}">новый{{ $c->creator ? ' · ' . $c->creator->name : '' }}</span>
                                    @endif
                                    <form x-show="merge" x-cloak method="POST" action="{{ route('fault-codes.merge', $c) }}" class="flex gap-2 mt-2" onsubmit="return confirm('Заменить {{ $c->code }} на выбранный код во всех записях и удалить {{ $c->code }}?')">
                                        @csrf
                                        <input name="target" required class="ui-input" style="width: 140px" placeholder="код, напр. К3-004">
                                        <button class="ui-btn ui-btn--primary ui-btn--sm">Объединить</button>
                                    </form>
                                </td>
                                <td x-show="!edit" style="color: var(--ui-muted)">{{ $c->group ? $c->group->label() : 'общий' }}</td>
                                <td x-show="!edit">{{ $c->is_active ? 'да' : 'нет' }}</td>
                                <td x-show="!edit" class="text-right whitespace-nowrap">
                                    @if($c->needs_review)
                                        <form method="POST" action="{{ route('fault-codes.approve', $c) }}" class="inline">@csrf @method('PATCH')<button class="ui-btn ui-btn--ghost ui-btn--sm" style="color: var(--ui-good)" title="Описание верное">Проверено</button></form>
                                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="merge = !merge" title="Это дубль существующего кода">Объединить</button>
                                    @endif
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
                                        <select name="group_id" class="ui-input">
                                            <option value="">Общий</option>
                                            @foreach($groups as $g)<option value="{{ $g->id }}" @selected($c->group_id === $g->id)>{{ $g->label() }}</option>@endforeach
                                        </select>
                                        <input name="hint" value="{{ $c->hint }}" maxlength="1000" class="ui-input w-full" placeholder="Разъяснение">
                                        <input type="hidden" name="section_id" value="{{ $c->section_id }}">
                                        <label class="text-sm flex items-center gap-1"><input type="checkbox" name="is_active" value="1" @checked($c->is_active)> активен</label>
                                        <button class="ui-btn ui-btn--primary ui-btn--sm">Сохранить</button>
                                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="edit = false">Отмена</button>
                                    </form>
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="5" class="text-center py-8" style="color: var(--ui-muted)">Кодов нет</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
        {{ $codes->links() }}
    </div>
</x-app-layout>
