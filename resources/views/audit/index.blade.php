<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"/></svg>
            </span>
            <div>
                <h2>Журнал действий</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Кто, когда и что изменил. Хранится {{ \App\Models\AuditLog::RETENTION_DAYS }} дней</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        <form method="GET" class="ui-card p-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-6 items-end">
            <label class="block lg:col-span-2">
                <span class="ui-label">Поиск</span>
                <input type="search" name="q" value="{{ $filter['q'] ?? '' }}" class="ui-input w-full" placeholder="Номер борта, ФИО, № записи…">
            </label>
            <label class="block">
                <span class="ui-label">Раздел</span>
                <select name="area" class="ui-input w-full">
                    <option value="">Все</option>
                    @foreach(\App\Models\AuditLog::AREAS as $k => $v)
                        <option value="{{ $k }}" @selected(($filter['area'] ?? '') === $k)>{{ $v }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="ui-label">Кто</span>
                <select name="user_id" class="ui-input w-full">
                    <option value="">Все</option>
                    @foreach($users as $u)
                        <option value="{{ $u->id }}" @selected((int) ($filter['user_id'] ?? 0) === $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </label>
            <label class="block">
                <span class="ui-label">С</span>
                <input type="date" name="from" value="{{ $filter['from'] ?? '' }}" class="ui-input w-full">
            </label>
            <label class="block">
                <span class="ui-label">По</span>
                <input type="date" name="to" value="{{ $filter['to'] ?? '' }}" class="ui-input w-full">
            </label>
            <div class="flex gap-2 lg:col-span-6">
                <button class="ui-btn ui-btn--primary">Показать</button>
                <a href="{{ route('audit.index') }}" class="ui-btn ui-btn--ghost">Сбросить</a>
            </div>
        </form>

        <div class="ui-card overflow-hidden">
            @forelse($logs as $log)
                <article class="ui-audit">
                    <div class="ui-audit__head">
                        <span class="ui-audit__badge ui-audit__badge--{{ $log->action }}">{{ $log->actionLabel() }}</span>
                        <span class="ui-audit__summary">{{ $log->summary }}</span>
                        @if($log->area === 'records' && $log->action !== 'deleted' && $log->subject_id && config("sheets.map.{$log->subject_type}"))
                            <a href="{{ route('records.show', [$log->subject_type, $log->subject_id]) }}" class="ui-audit__link">открыть</a>
                        @endif
                    </div>
                    <div class="ui-audit__meta">
                        <span>{{ $log->created_at->format('d.m.Y H:i:s') }}</span>
                        <span>{{ $log->user_name ?? '—' }}</span>
                        <span>{{ $log->areaLabel() }}</span>
                        @if($log->section)<span>{{ $log->section->name }}</span>@endif
                        @if($log->ip)<span class="font-mono">{{ $log->ip }}</span>@endif
                    </div>
                    @include('audit._changes', ['log' => $log, 'labels' => $labels])
                </article>
            @empty
                <p class="p-6 text-sm text-center" style="color: var(--ui-muted)">Записей нет</p>
            @endforelse
        </div>

        {{ $logs->links() }}
    </div>
</x-app-layout>
