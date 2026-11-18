<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.42 15.17L17.25 21A2.652 2.652 0 0021 17.25l-5.877-5.877M11.42 15.17l2.496-3.03c.317-.384.74-.626 1.208-.766M11.42 15.17l-4.655 5.653a2.548 2.548 0 11-3.586-3.586l6.837-5.63m5.108-.233c.55-.164 1.163-.188 1.743-.14a4.5 4.5 0 004.486-6.336l-3.276 3.277a3.004 3.004 0 01-2.25-2.25l3.276-3.276a4.5 4.5 0 00-6.336 4.486c.091 1.076-.071 2.264-.904 2.95l-.102.085"/></svg>
            </span>
            <div>
                <h2>Узлы ремонта и замены</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Свои варианты, предложенные ремонтниками. Блоки справочника кодов неисправностей здесь не показаны — они доступны всегда</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 space-y-5">
        @if(session('ok'))
            <div class="ui-card px-4 py-3 text-sm font-medium" role="status" style="background: var(--ui-good-soft); color: var(--ui-good); border-color: transparent">{{ session('ok') }}</div>
        @endif

        <section class="ui-card overflow-hidden">
            <div class="dr-head">
                <h3 class="dr-title">Ждут проверки · {{ $pending->count() }}</h3>
                <span class="dr-hint">подтвердите (можно поправить название) или отклоните</span>
            </div>
            @forelse($pending as $item)
                <div class="px-4 py-3 flex flex-wrap items-center gap-3" style="border-top: 1px solid var(--ui-border)">
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-semibold" style="color: var(--ui-text)">{{ $labels[$item->id] }}</div>
                        <div class="text-xs mt-0.5" style="color: var(--ui-muted)">
                            предложил {{ $item->creator?->name ?? '—' }} · {{ $item->created_at?->format('d.m.Y H:i') }}
                            · выбран в {{ $used[$item->id] ?? 0 }} исслед.
                        </div>
                    </div>
                    <form method="POST" action="{{ route('repair-items.approve', $item) }}" class="flex flex-wrap items-center gap-2">
                        @csrf @method('PATCH')
                        <input type="text" name="name" value="{{ $item->name }}" maxlength="150" class="ui-input !py-1.5 text-sm" aria-label="Название">
                        <button class="ui-btn ui-btn--primary ui-btn--sm">Подтвердить</button>
                    </form>
                    <form method="POST" action="{{ route('repair-items.reject', $item) }}" onsubmit="return confirm('Отклонить «{{ $item->name }}»?')">
                        @csrf @method('DELETE')
                        <button class="ui-btn ui-btn--ghost ui-btn--sm" style="color: var(--ui-bad)">Отклонить</button>
                    </form>
                </div>
            @empty
                <p class="dr-empty">Новых предложений нет</p>
            @endforelse
        </section>

        <section class="ui-card overflow-hidden">
            <div class="dr-head"><h3 class="dr-title">Подтверждённые · {{ $approved->count() }}</h3></div>
            @forelse($approved as $item)
                <div class="px-4 py-2.5 flex items-center justify-between gap-3 text-sm" style="border-top: 1px solid var(--ui-border)">
                    <span style="color: var(--ui-text)">{{ $labels[$item->id] }}</span>
                    <span class="text-xs whitespace-nowrap" style="color: var(--ui-muted)">в {{ $used[$item->id] ?? 0 }} исслед.</span>
                </div>
            @empty
                <p class="dr-empty">Пока нет</p>
            @endforelse
        </section>
    </div>
</x-app-layout>
