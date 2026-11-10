<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"/></svg>
            </span>
            <div>
                <h2>Участки</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">У каждого участка — свой учёт, статистика и новости</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
        @if(session('ok'))
            <div class="ui-card px-4 py-3 text-sm font-medium" role="status" style="background: var(--ui-good-soft); color: var(--ui-good); border-color: transparent">{{ session('ok') }}</div>
        @endif
        @if($errors->any())
            <div class="ui-card px-4 py-3 text-sm font-medium" role="alert" style="background: var(--ui-bad-soft); color: var(--ui-bad); border-color: transparent">
                @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        {{-- Список участков --}}
        <div class="ui-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="ui-table">
                    <thead>
                        <tr>
                            <th>Участок</th>
                            <th>Код</th>
                            <th class="text-right">Вкладок учёта</th>
                            <th class="text-right">Пользователей</th>
                            <th>Разделы</th>
                            <th>Состояние</th>
                            <th class="w-10"></th>
                        </tr>
                    </thead>
                    @foreach($sections as $section)
                        {{-- у каждого участка своя группа строк: строка + форма правки --}}
                        <tbody x-data="{ edit: false }">
                            <tr>
                                <td class="ui-table__strong">{{ $section->name }}</td>
                                <td><code class="text-xs px-1.5 py-0.5 rounded-md" style="background: var(--ui-surface-hover)">{{ $section->slug }}</code></td>
                                <td class="text-right tabular-nums">{{ count($section->sheets()) }}</td>
                                <td class="text-right tabular-nums">{{ $section->users_count }}</td>
                                <td>
                                    @if($section->has_firmware)<x-status-pill value="Прошивки" tone="neutral" />@else<span class="ui-table__muted">—</span>@endif
                                </td>
                                <td><x-status-pill :value="$section->is_active ? 'Активен' : 'Отключён'" :tone="$section->is_active ? 'good' : 'neutral'" /></td>
                                <td class="text-right">
                                    <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="edit = !edit" x-text="edit ? 'Закрыть' : 'Изменить'"></button>
                                </td>
                            </tr>
                            <tr x-show="edit" x-cloak>
                                <td colspan="7" class="!p-0">
                                    <div class="px-4 py-4" style="background: var(--ui-surface-2)">
                                        <form method="POST" action="{{ route('sections.update', $section) }}" class="flex flex-wrap items-end gap-3">
                                            @csrf @method('PATCH')
                                            <label class="flex-1 min-w-[220px]">
                                                <span class="block text-xs mb-1" style="color: var(--ui-muted)">Название</span>
                                                <input name="name" value="{{ $section->name }}" required maxlength="120" class="w-full rounded-[10px] px-3 py-2 text-sm">
                                            </label>
                                            <label class="w-28">
                                                <span class="block text-xs mb-1" style="color: var(--ui-muted)">Порядок</span>
                                                <input name="sort_order" type="number" min="0" value="{{ $section->sort_order }}" class="w-full rounded-[10px] px-3 py-2 text-sm">
                                            </label>
                                            <label class="inline-flex items-center gap-2 text-sm h-10">
                                                <input type="hidden" name="has_firmware" value="0">
                                                <input type="checkbox" name="has_firmware" value="1" @checked($section->has_firmware)> Прошивки
                                            </label>
                                            <label class="inline-flex items-center gap-2 text-sm h-10">
                                                <input type="hidden" name="is_active" value="0">
                                                <input type="checkbox" name="is_active" value="1" @checked($section->is_active)> Активен
                                            </label>
                                            <button class="ui-btn ui-btn--primary">Сохранить</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    @endforeach
                </table>
            </div>
        </div>

        {{-- Новый участок --}}
        <section class="ui-card p-5">
            <h3 class="text-base font-semibold mb-1" style="color: var(--ui-text)">Новый участок</h3>
            <p class="text-sm mb-4" style="color: var(--ui-muted)">
                После создания назначьте участок сотрудникам на странице «Пользователи».
                Вкладки учёта для участка добавляются в <code>config/sheets.php</code> с ключом
                <code>'section' => '&lt;код участка&gt;'</code>.
            </p>
            <form method="POST" action="{{ route('sections.store') }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <label class="flex-1 min-w-[220px]">
                    <span class="block text-xs mb-1" style="color: var(--ui-muted)">Название</span>
                    <input name="name" value="{{ old('name') }}" required maxlength="120" placeholder="Например: Участок сборки" class="w-full rounded-[10px] px-3 py-2 text-sm">
                </label>
                <label class="w-48">
                    <span class="block text-xs mb-1" style="color: var(--ui-muted)">Код (латиница, необязательно)</span>
                    <input name="slug" value="{{ old('slug') }}" maxlength="50" pattern="[a-z0-9-]+" placeholder="sborka" class="w-full rounded-[10px] px-3 py-2 text-sm font-mono">
                </label>
                <label class="inline-flex items-center gap-2 text-sm h-10">
                    <input type="checkbox" name="has_firmware" value="1"> Прошивки
                </label>
                <button class="ui-btn ui-btn--primary">Создать участок</button>
            </form>
        </section>
    </div>
</x-app-layout>
