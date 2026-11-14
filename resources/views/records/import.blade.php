<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/></svg>
            </span>
            <div>
                <h2>Загрузка из файла · {{ $tabs[$tab] ?? $tab }}</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">Сначала предпросмотр — в базу ничего не пишется, пока не нажмёте «Загрузить»</p>
            </div>
        </div>
    </x-slot>

    <div class="py-6 max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">
        @if($errors->any())
            <div class="ui-card px-4 py-3 text-sm font-medium" role="alert" style="background: var(--ui-bad-soft); color: var(--ui-bad); border-color: transparent">
                @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
            </div>
        @endif

        @php $cfgFields = app(\App\Services\SectionContext::class)->sheets()[$tab]['fields'] ?? []; @endphp
        <div class="ui-card p-4 space-y-3">
            <label class="block max-w-md">
                <span class="ui-label">В какую вкладку загружать</span>
                <select class="ui-input" onchange="location.href = this.value">
                    @foreach($tabs as $slug => $title)
                        <option value="{{ route('records.import.form', $slug) }}" @selected($slug === $tab)>{{ $title }}</option>
                    @endforeach
                </select>
            </label>

            <form method="POST" action="{{ route('records.import.preview', $tab) }}" enctype="multipart/form-data" class="space-y-3">
                @csrf
                <p class="text-sm" style="color: var(--ui-text-2)">
                    Таблица <b>.ods</b> (LibreOffice), <b>.xlsx / .xls</b> или <b>.csv</b>. Если листов несколько — берётся лист с названием вкладки
                    (или первый лист с её колонками), его можно сменить в предпросмотре. Загружаются только колонки, которые есть во вкладке
                    «{{ $tabs[$tab] ?? $tab }}», остальные пропускаются — новые поля и вкладки не создаются.
                </p>
                @if($tab === 'pgp')
                    <p class="text-xs" style="color: var(--ui-muted)">Колонки журнала ПГП: Дата, Номер поста, ФИО 1–3, Время начала / завершения работ, Номер борта, IP адрес модуля связи, Номер фюзеляжа, Сдан, Неисправен, Причина неисправности, Действия.</p>
                @else
                    <p class="text-xs" style="color: var(--ui-muted)">Колонки вкладки: {{ implode(', ', array_map(fn ($f) => $f['label'], array_filter($cfgFields, fn ($f, $k) => !in_array($k, ['worker2','worker3','worker4','worker5'], true), ARRAY_FILTER_USE_BOTH))) }} (исполнители — «ФИО 1», «ФИО 2»… или «Исполнитель 1»…).</p>
                @endif
                <ul class="text-xs list-disc list-inside space-y-0.5" style="color: var(--ui-muted)">
                    @if($tab === 'pgp')
                        <li>Дубль — тот же номер борта; без номера борта — та же дата, пост, № фюзеляжа и ФИО 1. Дубли не загружаются.</li>
                    @else
                        <li>Дубль — такая же запись уже есть (та же дата и те же значения всех заполненных колонок) или повторяется в файле. Дубли не загружаются.</li>
                    @endif
                    <li>ФИО сверяются со справочником сотрудников, в том числе с опечаткой в фамилии; не найденные записываются как в файле.</li>
                    <li>Время внесения записи — дата из файла и время завершения работ (если есть, иначе 12:00): так записи попадают в свою смену в отчётах.</li>
                </ul>
                <div class="flex flex-wrap items-center gap-2">
                    <input type="file" name="file" accept=".ods,.xlsx,.xls,.csv" required class="ui-input">
                    <button class="ui-btn ui-btn--primary">Проверить файл</button>
                    <a href="{{ route('records.index', $tab) }}" class="ui-btn ui-btn--ghost">Отмена</a>
                </div>
            </form>
        </div>

        @if($result && count($sheets ?? []) > 1)
            <form method="POST" action="{{ route('records.import.sheet', $tab) }}" class="ui-card p-4 flex flex-wrap items-end gap-3">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <label class="block">
                    <span class="ui-label">Лист в файле «{{ $fileName }}»</span>
                    <select name="sheet" class="ui-input" onchange="this.form.submit()">
                        @foreach($sheets as $name => $looksLikePgp)
                            <option value="{{ $name }}" @selected($name === $sheet)>{{ $name }}{{ $looksLikePgp ? '' : ' — нет колонок этой вкладки' }}</option>
                        @endforeach
                    </select>
                </label>
                <noscript><button class="ui-btn ui-btn--ghost">Показать лист</button></noscript>
            </form>
        @endif

        @if($result && $result['error'])
            <div class="ui-card px-4 py-3 text-sm font-medium" role="alert" style="background: var(--ui-bad-soft); color: var(--ui-bad); border-color: transparent">
                Лист «{{ $sheet }}»: {{ $result['error'] }}
            </div>
        @endif

        @if($result && !$result['error'])
            @php
                $rows = $result['rows'];
                $new = array_filter($rows, fn ($x) => !$x['error'] && !$x['duplicate']);
                $dups = array_filter($rows, fn ($x) => $x['duplicate']);
                $errs = array_filter($rows, fn ($x) => $x['error']);
                $similar = collect($rows)->flatMap(fn ($x) => $x['workers'])->where('kind', 'similar')->unique('raw');
                $unknown = collect($rows)->flatMap(fn ($x) => $x['workers'])->where('kind', 'unknown')->unique('raw');
            @endphp

            <div class="grid gap-3 sm:grid-cols-4">
                @foreach([['Строк в файле', count($rows), 'var(--ui-text)'], ['Будет добавлено', count($new), 'var(--ui-good)'], ['Дубли (пропуск)', count($dups), 'var(--ui-text-2)'], ['Ошибки (пропуск)', count($errs), count($errs) ? 'var(--ui-bad)' : 'var(--ui-text)']] as [$l, $v, $c])
                    <div class="ui-card p-4">
                        <div class="text-xs" style="color: var(--ui-muted)">{{ $l }}</div>
                        <div class="text-2xl font-bold mt-1" style="color: {{ $c }}">{{ $v }}</div>
                    </div>
                @endforeach
            </div>

            @if($result['missing'])
                <div class="ui-card px-4 py-3 text-sm" style="color: var(--ui-text-2)">
                    В файле не найдены колонки: <b>{{ implode(', ', $result['missing']) }}</b> — эти поля останутся пустыми.
                </div>
            @endif

            @if($similar->isNotEmpty() || $unknown->isNotEmpty())
                <div class="ui-card p-4 text-sm space-y-2">
                    @if($similar->isNotEmpty())
                        <div>
                            <div class="font-semibold mb-1" style="color: var(--ui-text)">Привязаны по похожей фамилии — проверьте:</div>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($similar as $w)
                                    <span class="rounded-md px-2 py-0.5 text-xs" style="background: var(--ui-surface-2); border: 1px solid var(--ui-border)">«{{ $w['raw'] }}» → <b>{{ $w['name'] }}</b></span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    @if($unknown->isNotEmpty())
                        <div>
                            <div class="font-semibold mb-1" style="color: var(--ui-text)">Нет в справочнике — запишутся как в файле:</div>
                            <div class="flex flex-wrap gap-1.5">
                                @foreach($unknown as $w)
                                    <span class="ui-badge-warn">{{ $w['raw'] }}</span>
                                @endforeach
                            </div>
                        </div>
                    @endif
                </div>
            @endif

            <form method="POST" action="{{ route('records.import.save', $tab) }}" class="flex flex-wrap items-center gap-3">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <button class="ui-btn ui-btn--primary" @disabled(!count($new))>Загрузить новые записи ({{ count($new) }})</button>
                <span class="text-sm" style="color: var(--ui-muted)">{{ $fileName ?? '' }}@if(!empty($sheet)) · лист «{{ $sheet }}»@endif · предпросмотр действует час</span>
            </form>

            <section class="ui-card overflow-hidden">
                <div class="overflow-x-auto">
                    <table class="ui-table text-sm">
                        <thead>
                            <tr><th>Стр.</th><th>Итог</th>@foreach($columns as $label)<th>{{ $label }}</th>@endforeach<th>Исполнители</th><th>Замечания</th></tr>
                        </thead>
                        <tbody>
                            @foreach($rows as $row)
                                @php $d = $row['data']; @endphp
                                <tr style="{{ $row['error'] ? 'background: var(--ui-bad-soft)' : ($row['duplicate'] ? 'opacity: .6' : '') }}">
                                    <td class="tabular-nums">{{ $row['line'] }}</td>
                                    <td class="whitespace-nowrap">
                                        @if($row['error'])<span style="color: var(--ui-bad); font-weight: 600">ошибка</span>
                                        @elseif($row['duplicate'])<span style="color: var(--ui-muted)">дубль</span>
                                        @else<span style="color: var(--ui-good); font-weight: 600">добавится</span>@endif
                                    </td>
                                    @foreach($columns as $col => $label)
                                        @php
                                            $v = $d[$col] ?? null;
                                            $opts = $cfgFields[$col]['options'] ?? null;
                                            if ($col === 'record_date' && $v) $v = \Carbon\Carbon::parse($v)->format('d.m.Y');
                                            elseif ($v !== null && is_array($opts) && array_key_exists($v, $opts) && !array_is_list($opts)) $v = $opts[$v];
                                        @endphp
                                        <td class="{{ $col === 'record_date' ? 'whitespace-nowrap tabular-nums' : '' }}">
                                            @if($col === 'status' && $v)<x-status-pill :value="$v" />@else{{ $v !== null && $v !== '' ? $v : '—' }}@endif
                                        </td>
                                    @endforeach
                                    <td>
                                        @foreach($row['workers'] as $w)
                                            <div class="whitespace-nowrap">
                                                {{ $w['name'] }}
                                                @if($w['kind'] === 'similar')<span class="text-xs" style="color: var(--ui-muted)" title="В файле: {{ $w['raw'] }}">(в файле «{{ $w['raw'] }}»)</span>
                                                @elseif($w['kind'] === 'unknown')<span class="ui-badge-warn">нет в справочнике</span>@endif
                                            </div>
                                        @endforeach
                                    </td>
                                    <td class="text-xs" style="color: var(--ui-text-2)">
                                        @if($row['error'])<div style="color: var(--ui-bad)">{{ $row['error'] }}</div>@endif
                                        @if($row['duplicate'])<div>{{ $row['duplicate'] }}</div>@endif
                                        @foreach($row['warnings'] as $w)<div>{{ $w }}</div>@endforeach
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        @endif
    </div>
</x-app-layout>
