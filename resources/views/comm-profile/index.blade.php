<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/></svg>
            </span>
            <div>
                <h2>Профиль модулей связи</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Значения прошивки и бэкап настроек .msconf — общие для всех MANET Flasher (режим «с сервера»)</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('comm-licenses.index') }}" class="ui-btn ui-btn--ghost ui-btn--sm">Лицензии модулей связи →</a>
        </div>
        @if(session('status'))
            <div class="ui-card px-4 py-3 text-sm" style="color: var(--ui-good)">{{ session('status') }}</div>
        @endif
        @if($errors->any())
            <div class="ui-card px-4 py-3 text-sm" style="color: var(--ui-bad)">
                @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        {{-- бэкап настроек --}}
        <div class="ui-card p-4 space-y-3">
            <div class="font-semibold">Бэкап настроек (.msconf) — импорт «Update All Nodes»</div>
            <form method="POST" action="{{ route('comm-profile.msconf.upload') }}" enctype="multipart/form-data" class="flex flex-wrap items-center gap-3">
                @csrf
                <input type="file" name="msconf" accept=".msconf" class="text-sm" required>
                <label class="text-sm flex items-center gap-1.5"><input type="checkbox" name="make_active" value="1" checked> сделать рабочим</label>
                <button class="ui-btn ui-btn--primary ui-btn--sm">Загрузить</button>
            </form>
            <table class="ui-table">
                <thead><tr><th>Файл</th><th>Версия ПО</th><th>Сеть</th><th style="width: 140px">Загружен</th><th style="width: 220px"></th></tr></thead>
                <tbody>
                    @forelse($msconfs as $m)
                        <tr>
                            <td>
                                <a href="{{ route('comm-profile.msconf.download', $m['name']) }}" class="font-mono text-sm">{{ $m['name'] }}</a>
                                @if($m['active'])<span class="ui-badge-warn" style="background: rgba(34,197,94,.15); color: var(--ui-good)">рабочий</span>@endif
                            </td>
                            <td class="font-mono text-sm">{{ $m['version'] }}</td>
                            <td>{{ $m['mesh'] }}</td>
                            <td style="color: var(--ui-muted)">{{ date('d.m.Y H:i', $m['mtime']) }}</td>
                            <td class="text-right whitespace-nowrap">
                                @unless($m['active'])
                                    <form method="POST" action="{{ route('comm-profile.msconf.activate', $m['name']) }}" class="inline">@csrf
                                        <button class="ui-btn ui-btn--ghost ui-btn--sm">Сделать рабочим</button></form>
                                @endunless
                                <form method="POST" action="{{ route('comm-profile.msconf.destroy', $m['name']) }}" class="inline" onsubmit="return confirm('Удалить {{ $m['name'] }}?')">
                                    @csrf @method('DELETE')
                                    <button class="ui-btn ui-btn--ghost ui-btn--sm" style="color: var(--ui-bad)" aria-label="Удалить">✕</button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-center py-4" style="color: var(--ui-muted)">Файлов нет — загрузите MeshSameParams.msconf</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- значения прошивки --}}
        <form method="POST" action="{{ route('comm-profile.params') }}" class="ui-card p-4 space-y-4">
            @csrf
            <div class="flex items-center justify-between">
                <div class="font-semibold">Значения прошивки</div>
                @if($settings['updated_at'])
                    <div class="text-xs" style="color: var(--ui-muted)">изменено {{ \Carbon\Carbon::parse($settings['updated_at'])->format('d.m.Y H:i') }}{{ $settings['updated_by'] ? ' · ' . $settings['updated_by'] : '' }}</div>
                @endif
            </div>
            @foreach($groups as $title => $params)
                <div>
                    <div class="text-sm font-semibold mb-2" style="color: var(--ui-muted)">{{ $title }}</div>
                    <div class="grid gap-3 sm:grid-cols-2">
                        @foreach($params as $key => $p)
                            @php $val = old($key, $settings['params'][$key]); @endphp
                            @if($p['type'] === 'bool')
                                <label class="text-sm flex items-center gap-2">
                                    <input type="hidden" name="{{ $key }}" value="0">
                                    <input type="checkbox" name="{{ $key }}" value="1" @checked($val)> {{ $p['label'] }}
                                </label>
                            @else
                                <label class="text-sm">{{ $p['label'] }}
                                    <input name="{{ $key }}" value="{{ $val }}" class="ui-input mt-1 w-full {{ $p['type'] !== 'str' ? 'font-mono' : '' }}"
                                           @if($p['type'] !== 'str') inputmode="decimal" @endif>
                                </label>
                            @endif
                        @endforeach
                    </div>
                </div>
            @endforeach
            <p class="text-xs" style="color: var(--ui-muted)">Мощность антенн считается от калибровки: при Maximum 5000 мВт и RF Attenuation 9 итоговые 10 мВт = аттенюация 36 (= TX1/TX2 в RF Measurement), проверка 5 Вт = 9.</p>
            <button class="ui-btn ui-btn--primary">Сохранить</button>
        </form>

        {{-- История изменений профиля (журнал действий, раздел «Профиль модулей связи») --}}
        <section class="ui-card overflow-hidden">
            <div class="px-4 pt-4 font-semibold">История изменений</div>
            <div class="px-1 pb-2">
                @forelse($history as $log)
                    <div class="ui-audit ui-audit--compact">
                        <div class="ui-audit__head">
                            <span class="ui-audit__badge ui-audit__badge--{{ $log->action }}">{{ $log->actionLabel() }}</span>
                            <span class="text-sm">{{ $log->summary }}</span>
                        </div>
                        <div class="ui-audit__meta">
                            <span>{{ $log->created_at->format('d.m.Y H:i') }}</span>
                            <span>{{ $log->user_name }}</span>
                        </div>
                        @if($log->action === 'updated')
                            @include('audit._changes', ['log' => $log, 'labels' => $labels])
                        @endif
                    </div>
                @empty
                    <p class="px-3 py-3 text-sm" style="color: var(--ui-muted)">Изменений пока не было.</p>
                @endforelse
            </div>
        </section>
    </div>
</x-app-layout>
