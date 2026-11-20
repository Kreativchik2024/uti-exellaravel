{{--
    Форма ПГП: борт с введённым фюзеляжем уже есть (App\Services\PgpBoardLookup::target).
    Вернулся после ремонта — эта запись станет повторной проверкой; данные борта подставляются в пустые поля
    (data-board-prefill, public/js/record-form.js). В ремонте или уже сдан — новая запись не сохранится.
    $existing — PgpBoardLookup::target(); $isEdit — форма правки другой записи.
--}}
@php $rec = $existing['record']; $returned = $existing['state'] === 'returned'; @endphp
<div class="mb-4 rounded-lg px-4 py-3 text-sm" role="alert"
     style="background: {{ $returned ? 'var(--ui-accent-soft)' : 'var(--ui-bad-soft, var(--ui-surface-2))' }}; color: var(--ui-text); border: 1px solid var(--ui-border)"
     @if($returned && ! $isEdit) data-board-prefill='@json($existing['prefill'])' @endif>
    @if($isEdit)
        <b>Этот фюзеляж уже есть в записи №{{ $rec->id }}</b>
        ({{ $rec->record_date?->format('d.m.Y') }}, {{ $rec->status ?: 'без статуса' }}) — проверьте, не дубль ли это.
    @else
        <b>{{ $returned ? 'Повторная проверка после ремонта.' : 'Новую запись по этому борту сейчас не завести.' }}</b> {{ $existing['text'] }}
    @endif
    <a href="{{ $existing['url'] }}" class="underline font-semibold ml-1">Открыть запись №{{ $rec->id }}</a>
</div>
