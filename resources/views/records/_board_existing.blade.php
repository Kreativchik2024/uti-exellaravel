{{--
    Форма ПГП: борт с введённым фюзеляжем уже есть (App\Services\PgpBoardLookup).
    Новая запись — нет: кнопка отправляет форму, сервер открывает запись борта с уже введёнными данными
    (RecordController::store, до проверки полей). Нельзя править — ссылка на карточку.
    $existing — PgpBoardLookup::target(); $isEdit — форма правки другой записи.
--}}
@php $rec = $existing['record']; @endphp
<div class="mb-4 rounded-lg px-4 py-3 text-sm" role="alert"
     style="background: {{ $existing['state'] === 'returned' ? 'var(--ui-accent-soft)' : 'var(--ui-surface-2)' }}; color: var(--ui-text); border: 1px solid var(--ui-border)">
    @if($isEdit)
        <b>Этот фюзеляж уже есть в записи №{{ $rec->id }}</b>
        ({{ $rec->record_date?->format('d.m.Y') }}, {{ $rec->status ?: 'без статуса' }}) — проверьте, не дубль ли это.
        <a href="{{ route('records.show', ['pgp', $rec->id]) }}" class="underline font-semibold ml-1">Открыть</a>
    @else
        <b>Этот борт уже есть.</b> {{ $existing['text'] }}
        <div class="mt-2.5 flex flex-wrap gap-2">
            @if($existing['editable'])
                <button type="submit" name="open_existing" value="1" formnovalidate
                        class="tap-44 inline-flex items-center gap-1.5 bg-blue-600 hover:bg-blue-700 text-white font-semibold px-4 py-2 rounded-lg shadow transition">
                    Открыть запись борта и продолжить
                </button>
            @else
                <a href="{{ $existing['url'] }}" class="tap-44 inline-flex items-center px-4 py-2 rounded-lg font-semibold border"
                   style="border-color: var(--ui-border); background: var(--ui-surface)">Открыть карточку борта</a>
            @endif
        </div>
        <p class="mt-2 text-xs" style="color: var(--ui-muted)">Если сохранить форму, новая запись всё равно не создастся — откроется запись этого борта.</p>
    @endif
</div>
