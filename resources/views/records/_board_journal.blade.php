{{--
    Журнал борта по этапам: прошивка и настройка → сборка → ПГП → ремонт → снова ПГП → ОТК (App\Services\BoardHistory).
    Используется на карточке ПГП и в форме ПГП («Что известно об этом борте» — по введённым номерам).
    $boardHistory — данные; $title — заголовок; $sectionId — id блока; $draft — вид для формы (без «Сейчас» и пустых этапов).
--}}
@php
    $title     ??= 'Журнал борта' . ($boardHistory['board'] ? ' ' . $boardHistory['board'] : '');
    $sectionId ??= 'board-history';
    $draft     ??= false;
    $keyLabels = ['frame_serial' => 'моторама', 'fuselage_number' => 'фюзеляж', 'board_number' => 'борт'];
    $canSeeChanges = auth()->user()->canManageContent() || auth()->user()->isOtk();
@endphp
<section id="{{ $sectionId }}" class="ui-card overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-2 px-4 pt-3 pb-1">
        <h3 class="rec-section__title !p-0">{{ $title }}</h3>
        <span class="flex items-center gap-1.5 text-xs" style="color: var(--ui-muted)">
            Сейчас: <x-status-pill :value="$boardHistory['stage']['label']" :tone="$boardHistory['stage']['tone']" />
        </span>
    </div>
    <p class="px-4 text-xs" style="color: var(--ui-muted)">
        @if($boardHistory['keys'])
            Собрано по номерам:
            {{ collect($boardHistory['keys'])->map(fn ($v, $k) => ($keyLabels[$k] ?? $k) . ' ' . $v)->implode(', ') }}
        @elseif($draft)
            По номерам периферии; введите № моторамы или фюзеляжа — подтянется вся история борта
        @else
            Номера моторамы и фюзеляжа не указаны — показана только эта запись
        @endif
    </p>

    {{-- Этапы: сколько событий на каждом --}}
    <div class="bj-stages px-4 pt-3">
        @foreach($boardHistory['stages'] as $key => $st)
            <span class="bj-stage {{ $st['count'] ? 'bj-stage--done' : '' }}" title="{{ $st['count'] }} {{ $st['count'] === 1 ? 'событие' : 'событий' }}">
                {{ $st['label'] }}@if($st['count'])<b>{{ $st['count'] }}</b>@endif
            </span>
        @endforeach
    </div>

    {{-- Лента по времени: этап, что произошло, кто делал и кто внёс --}}
    <ol class="px-4 py-3 space-y-2.5">
        @forelse($boardHistory['events'] as $ev)
            <li class="flex gap-3">
                <span class="ui-pill--{{ $ev['tone'] }} w-2.5 h-2.5 mt-1.5 shrink-0 rounded-full" aria-hidden="true" style="background: var(--pill-fg)"></span>
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-baseline justify-between gap-x-3 text-sm">
                        <span class="min-w-0">
                            <span class="bj-chip">{{ \App\Services\BoardHistory::STAGES[$ev['stage']] ?? '' }}</span>
                            @if($ev['url'])
                                <a href="{{ $ev['url'] }}" @if($draft) target="_blank" @endif class="font-medium hover:underline" style="color: var(--ui-text)">{{ $ev['title'] }}</a>
                            @else
                                <span class="font-medium" style="color: var(--ui-text)">{{ $ev['title'] }}</span>
                            @endif
                        </span>
                        <span class="text-xs tabular-nums" style="color: var(--ui-muted)">{{ $ev['at']?->format('d.m.Y H:i') }}</span>
                    </div>
                    @if($ev['who'] || $ev['by'])
                        <div class="text-xs" style="color: var(--ui-muted)">
                            @if($ev['who'])делал: <span style="color: var(--ui-text-2)">{{ $ev['who'] }}</span>@endif
                            @if($ev['who'] && $ev['by'] && $ev['by'] !== $ev['who']) · @endif
                            @if($ev['by'] && $ev['by'] !== $ev['who'])внёс: <span style="color: var(--ui-text-2)">{{ $ev['by'] }}</span>@endif
                        </div>
                    @endif
                    @if($ev['text'])
                        <div class="text-sm whitespace-pre-line" style="color: var(--ui-text-2)">{{ $ev['text'] }}</div>
                    @endif
                </div>
            </li>
        @empty
            <li class="text-sm" style="color: var(--ui-muted)">{{ $draft ? 'По этим номерам ничего не найдено — борт новый' : 'Событий нет' }}</li>
        @endforelse
    </ol>

    {{-- Все изменения записей борта: кто, когда, что было и что стало (мастер, ОТК, админ) --}}
    @if($canSeeChanges && $boardHistory['changes'])
        <details class="bj-changes px-4 pb-4">
            <summary class="cursor-pointer text-sm font-semibold" style="color: var(--ui-accent-text)">
                Журнал изменений · {{ count($boardHistory['changes']) }}
            </summary>
            <ul class="mt-2 space-y-2">
                @foreach($boardHistory['changes'] as $ch)
                    <li class="rounded-lg px-3 py-2" style="background: var(--ui-surface-2)">
                        <div class="flex flex-wrap items-baseline justify-between gap-x-3 text-xs">
                            <span>
                                @if($ch['url'])<a href="{{ $ch['url'] }}" @if($draft) target="_blank" @endif class="font-semibold hover:underline" style="color: var(--ui-text)">{{ $ch['record'] }}</a>
                                @else<span class="font-semibold" style="color: var(--ui-text)">{{ $ch['record'] }}</span>@endif
                                <span style="color: var(--ui-muted)">{{ $ch['action'] }} · {{ $ch['who'] ?? '—' }}</span>
                            </span>
                            <span class="tabular-nums" style="color: var(--ui-muted)">{{ $ch['at']?->format('d.m.Y H:i') }}</span>
                        </div>
                        @foreach($ch['fields'] as $fd)
                            <div class="text-xs mt-0.5" style="color: var(--ui-text-2)">
                                {{ $fd['label'] }}: <s style="color: var(--ui-muted)">{{ $fd['old'] ?? '—' }}</s> → <b style="color: var(--ui-text)">{{ $fd['new'] ?? '—' }}</b>
                            </div>
                        @endforeach
                    </li>
                @endforeach
            </ul>
        </details>
    @endif
</section>
