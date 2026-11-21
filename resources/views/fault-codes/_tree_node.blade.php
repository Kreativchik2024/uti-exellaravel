{{-- Ступенька дерева кодов: уровень, под ним — вложенные уровни и его коды. $g, $kids, $treeCodes, $tree, $sheets --}}
@php
    $children = $kids[$g->id] ?? collect();
    $own = $treeCodes[$g->id] ?? collect();
    $total = collect($g->selfAndDescendantIds($tree))->sum(fn ($id) => count($treeCodes[$id] ?? []));
    $hasBody = $children->isNotEmpty() || $own->isNotEmpty();
@endphp
<div class="fc-node" x-data="{ open: {{ $focus && in_array($g->id, $focus['open'], true) ? 'true' : 'false' }}, edit: false }" @fc-tree.window="open = $event.detail">
    <div class="fc-node__row {{ $g->parent_id ? '' : 'is-top' }}">
        <button type="button" class="fc-node__toggle" @click="open = !open" :aria-expanded="open.toString()"
                aria-label="Раскрыть «{{ $g->name }}»" @if(! $hasBody) disabled @endif>
            <svg :class="open && 'is-open'" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M7 5l6 5-6 5z"/></svg>
        </button>
        <button type="button" class="fc-node__name" @click="open = !open" @if(! $hasBody) disabled @endif>
            <span class="ui-fc-code">{{ $g->prefix }}</span>
            <span>{{ $g->numberedName() }}</span>
        </button>
        <span class="fc-node__meta">
            <span title="Уровень">Р{{ $g->depth() }}</span>
            @if($total)<span>· {{ $total }} код.</span>@endif
            @if($g->sheets) <span>· вкладки: {{ collect($g->sheets)->map(fn ($s) => $sheets[$s] ?? $s)->implode(', ') }}</span>
            @elseif(! $g->parent_id && ! $g->effectiveSheets()) <span style="color:#b45309">· не привязан к вкладкам — коды не выпадают</span>
            @endif
        </span>
        <span class="fc-node__actions">
            <a href="{{ route('fault-codes.index', ['group' => $g->id]) }}#fc-table" class="ui-btn ui-btn--ghost ui-btn--sm" title="Показать коды в таблице ниже">В таблице</a>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="edit = !edit">Изменить</button>
            <form method="POST" action="{{ route('fault-codes.groups.destroy', $g) }}" onsubmit="return confirm('Удалить? Коды станут общими, разделы внутри подгруппы удалятся.')">
                @csrf @method('DELETE')
                <button class="ui-btn ui-btn--ghost ui-btn--sm" style="color: var(--ui-bad)" aria-label="Удалить «{{ $g->name }}»">✕</button>
            </form>
        </span>
    </div>
    <form x-show="edit" x-cloak method="POST" action="{{ route('fault-codes.groups.update', $g) }}" class="fc-node__edit">
        @csrf @method('PATCH')
        @include('fault-codes._group_fields', ['g' => $g])
        <button class="ui-btn ui-btn--primary ui-btn--sm mt-2">Сохранить</button>
    </form>

    @if($hasBody)
        <div class="fc-node__body" x-show="open" x-cloak>
            @foreach($children as $child)
                @include('fault-codes._tree_node', ['g' => $child])
            @endforeach
            @if($own->isNotEmpty())
                <ul class="fc-codes">
                    @foreach($own as $c)
                        <li class="fc-code {{ $c->is_active ? '' : 'is-off' }}">
                            <a href="{{ route('fault-codes.index', ['q' => $c->code]) }}#fc-table" class="fc-code__code" title="Открыть в таблице для правки">{{ $c->code }}</a>
                            <span class="fc-code__desc">
                                {{ $c->description ?: '—' }}
                                @if($c->needs_review)<span class="ui-badge-warn">новый</span>@endif
                                @if(! $c->is_active)<span class="fc-code__off">отключён</span>@endif
                                @if($c->hint)<span class="fc-code__hint">{{ $c->hint }}</span>@endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    @endif
</div>
