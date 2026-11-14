<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h16"/></svg>
            </span>
            <div>
                <h2>Записи смены{{ $onlyDefect ? ' — брак' : '' }}</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">
                    {{ $from->format('d.m.Y H:i') }} — {{ $to->format('d.m.Y H:i') }}
                    @if($sheetTitle) · {{ $sheetTitle }} @endif
                    @if($worker) · {{ $worker }} @endif
                </p>
            </div>
        </div>
    </x-slot>

    @php $base = ['date' => $date->format('Y-m-d'), 'shift' => $shift]; @endphp

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('reports.shift', $base) }}" class="ui-btn ui-btn--ghost">← К отчёту по смене</a>
            @if($onlyDefect)
                <a href="{{ route('reports.shift.records', array_filter($base + ['sheet' => $sheet, 'worker' => $worker])) }}" class="ui-btn ui-btn--ghost">Показать все записи</a>
            @else
                <a href="{{ route('reports.shift.records', array_filter($base + ['sheet' => $sheet, 'worker' => $worker, 'defect' => 1])) }}" class="ui-btn ui-btn--ghost">Только брак</a>
            @endif
        </div>

        @forelse($groups as $slug => $g)
            <section class="ui-card overflow-hidden">
                <h3 class="px-4 pt-3 pb-2 text-xs font-semibold uppercase tracking-wide flex items-center gap-2" style="color: var(--ui-muted)">
                    <a href="{{ route('records.index', $slug) }}" style="color: var(--ui-accent-text)">{{ $g['title'] }}</a>
                    <span>· {{ $g['records']->count() }}</span>
                </h3>
                @include('reports._records-table', ['slug' => $slug, 'cfg' => $g['cfg'], 'columns' => $g['columns'], 'records' => $g['records'], 'defectIds' => $g['defectIds'], 'timeOnly' => true])
            </section>
        @empty
            <div class="ui-card p-8 text-center text-sm" style="color: var(--ui-muted)">Записей нет</div>
        @endforelse
    </div>
</x-app-layout>
