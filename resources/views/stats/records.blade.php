<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </span>
            <div>
                @php
                    $kinds = $board !== null ? ['all' => 'Все', 'good' => 'Сдано', 'defect' => 'Не сдано'] : ['all' => 'Все', 'good' => 'Исправные', 'defect' => 'Брак'];
                @endphp
                <h2>{{ $cfg['title'] }}@if($boardLabel) · {{ $boardLabel }}@endif{{ $kind !== 'all' ? ' — ' . mb_strtolower($kinds[$kind]) : '' }}</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">
                    {{ $periodLabel }} · записей: {{ number_format($records->total(), 0, ',', ' ') }}
                    @if($worker) · исполнитель: {{ $worker }} @endif
                </p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('stats.index', $filters) }}" class="ui-btn ui-btn--ghost">← К статистике</a>
            @foreach($kinds as $k => $label)
                <a href="{{ route('stats.records', $filters + array_filter(['sheet' => $slug, 'kind' => $k, 'board' => $board])) }}"
                   class="ui-btn ui-btn--sm {{ $kind === $k ? 'ui-btn--primary' : 'ui-btn--ghost' }}"
                   @if($kind === $k) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </div>

        @if($records->isEmpty())
            <div class="ui-card p-8 text-center text-sm" style="color: var(--ui-muted)">Записей нет</div>
        @else
            <section class="ui-card overflow-hidden">
                @include('reports._records-table', ['slug' => $slug, 'cfg' => $cfg, 'columns' => $columns, 'records' => $records, 'defectIds' => $defectIds])
            </section>
            <div class="pt-2">{{ $records->links() }}</div>
        @endif
    </div>
</x-app-layout>
