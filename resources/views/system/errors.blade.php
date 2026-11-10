<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </span>
            <div>
                <h2>Ошибки сайта</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Из логов сервера · одинаковые собраны в одну строку</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('system.index') }}" class="ui-btn ui-btn--ghost ui-btn--sm">← Состояние сервера</a>
            <span class="mx-1" style="color: var(--ui-faint)">|</span>
            @foreach([1 => 'Последний час', 24 => 'Сутки', 72 => '3 дня', 168 => 'Неделя'] as $h => $label)
                <a href="{{ route('system.errors', ['hours' => $h]) }}" class="ui-btn ui-btn--sm {{ $hours === $h ? 'ui-btn--primary' : 'ui-btn--ghost' }}">{{ $label }}</a>
            @endforeach
            <span class="ml-auto text-sm" style="color: var(--ui-muted)">Всего: <strong style="color: var(--ui-text)">{{ $total }}</strong>, разных: {{ count($groups) }}</span>
        </div>

        @forelse($groups as $g)
            <details class="ui-card ui-errlog">
                <summary class="ui-errlog__head">
                    <span class="ui-errlog__count" title="Сколько раз">{{ $g['count'] }}×</span>
                    <span class="min-w-0 flex-1">
                        <span class="ui-errlog__msg">{{ $g['message'] }}</span>
                        <span class="ui-errlog__meta">
                            <span>последняя {{ \Carbon\Carbon::parse($g['time'])->format('d.m H:i:s') }}</span>
                            @if($g['count'] > 1)<span>первая {{ \Carbon\Carbon::parse($g['first'])->format('d.m H:i:s') }}</span>@endif
                            @if($g['users'])
                                <span>пользователи: {{ collect($g['users'])->map(fn ($id) => $users[$id] ?? "#$id")->implode(', ') }}</span>
                            @endif
                            <span class="ui-errlog__level">{{ $g['level'] }}</span>
                        </span>
                    </span>
                    <svg class="ui-errlog__chev w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </summary>
                <div class="ui-errlog__body">
                    @if($g['exception'])<div><span style="color: var(--ui-muted)">Тип:</span> <code>{{ $g['exception'] }}</code></div>@endif
                    @if($g['location'])<div><span style="color: var(--ui-muted)">Где:</span> <code>{{ $g['location'] }}</code></div>@endif
                    @if($g['trace'])
                        <div class="mt-2" style="color: var(--ui-muted)">Цепочка вызовов (последний случай):</div>
                        <pre class="ui-errlog__trace">{{ implode("\n", $g['trace']) }}</pre>
                    @endif
                </div>
            </details>
        @empty
            <div class="ui-card p-10 text-center">
                <div class="text-lg font-semibold" style="color: var(--ui-good)">Ошибок нет</div>
                <p class="text-sm mt-1" style="color: var(--ui-muted)">За выбранный период в логах нет ни одной ошибки</p>
            </div>
        @endforelse
    </div>
</x-app-layout>
