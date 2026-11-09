<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
            <div class="flex items-center gap-3.5">
                <span class="ui-icon-tile">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 20H5a2 2 0 01-2-2V6a2 2 0 012-2h10a2 2 0 012 2v1m2 13a2 2 0 01-2-2V7m2 13a2 2 0 002-2V9a2 2 0 00-2-2h-2m-4-3H9M7 16h6M7 8h6v4H7V8z"/></svg>
                </span>
                <div>
                    <h2>Новости</h2>
                    <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Всего: {{ number_format($news->total(), 0, ',', ' ') }}</p>
                </div>
            </div>
            @if(auth()->user()?->canManageContent())
                <a href="{{ route('news.create') }}" class="ui-btn ui-btn--primary">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.2" d="M12 4v16m8-8H4"/></svg>
                    Новая новость
                </a>
            @endif
        </div>
    </x-slot>

    <div class="py-6 max-w-3xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if(session('ok'))
            <div class="ui-card px-4 py-3 text-sm font-medium" role="status" style="background: var(--ui-good-soft); color: var(--ui-good); border-color: transparent">{{ session('ok') }}</div>
        @endif

        @if($news->count())
            @include('news._items', ['news' => $news, 'manage' => true])
        @else
            <div class="ui-card p-12 text-center text-sm" style="color: var(--ui-muted)">Пока нет новостей.</div>
        @endif

        <div class="pt-2">{{ $news->links() }}</div>
    </div>
</x-app-layout>
