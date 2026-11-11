{{-- Поля уровня справочника: $g — запись или null; $groups — все (дерево); $sheets — [slug => название вкладки] --}}
@php($own = $g ? $g->selfAndDescendantIds($groups->keyBy('id')) : [])
<div class="grid gap-3 sm:grid-cols-4">
    <label class="block sm:col-span-2"><span class="ui-label">Название</span>
        <input name="name" value="{{ $g?->name }}" required maxlength="120" class="ui-input w-full" placeholder="МОТОРАМА, Кросс 3 (Cross 3) или МИНИ ПК"></label>
    <label class="block"><span class="ui-label">Входит в</span>
        <select name="parent_id" class="ui-input w-full">
            <option value="">— верхний уровень (Р1) —</option>
            @foreach($groups as $p)
                @unless(in_array($p->id, $own, true))<option value="{{ $p->id }}" @selected($g && $g->parent_id === $p->id)>{{ $p->label() }}</option>@endunless
            @endforeach
        </select></label>
    <label class="block"><span class="ui-label">Основа кода</span>
        <input name="prefix" value="{{ $g?->prefix }}" maxlength="30" class="ui-input w-full font-mono" placeholder="C(3).3 → C(3).3.1"></label>
</div>
<div class="mt-2">
    <span class="ui-label">Вкладки, где выпадают коды (плюс вкладки уровней выше)</span>
    <div class="flex flex-wrap gap-x-4 gap-y-1">
        @foreach($sheets as $slug => $title)
            <label class="text-sm flex items-center gap-1.5" style="color: var(--ui-text-2)">
                <input type="checkbox" name="sheets[]" value="{{ $slug }}" @checked($g && in_array($slug, (array) $g->sheets, true))> {{ $title }}
            </label>
        @endforeach
    </div>
</div>
