<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3.5">
            <span class="ui-icon-tile">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
            </span>
            <div>
                <h2>Причины брака</h2>
                <p class="text-sm mt-0.5" style="color: var(--ui-muted)">
                    {{ $from->format('d.m.Y') }} — {{ $to->format('d.m.Y') }}
                    · {{ $sheet ? ($sheets[$sheet]['title'] ?? $sheet) : 'все вкладки участка' }}
                </p>
            </div>
        </div>
    </x-slot>

    @php
        // ---------- числа и сравнения ----------
        $rate   = fn (int $p, int $t) => $t ? $p / $t * 100 : null;              // доля, %
        $fmtPct = fn (?float $v, int $dec = 1) => $v === null ? '—' : str_replace('.', ',', (string) round($v, $dec)) . '%';
        $num    = fn ($n) => number_format((int) $n, 0, ',', ' ');
        // 1 случай, 3 случая, 5 случаев, 21 случай
        $plural = fn (int $n, string $one, string $few, string $many) => ($n % 10 === 1 && $n % 100 !== 11) ? $one
            : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) ? $few : $many);
        $defects = (int) $data['defect'];
        $nowRate  = $rate($defects, (int) $data['total']);
        $prevRate = $rate((int) $prev['defect'], (int) $prev['total']);
        // разница в процентных пунктах; рост брака — плохо (красный), снижение — хорошо (зелёный)
        $delta = ($nowRate !== null && $prevRate !== null) ? $nowRate - $prevRate : null;
        $trend = function (?float $d, string $unit = ' п.п.') {
            if ($d === null) return ['text' => 'нет данных за прошлый период', 'tone' => 'muted'];
            if (abs($d) < 0.05) return ['text' => 'как в прошлом периоде', 'tone' => 'muted'];
            return ['text' => ($d > 0 ? '▲ +' : '▼ −') . str_replace('.', ',', (string) round(abs($d), 1)) . $unit . ' к прошлому периоду', 'tone' => $d > 0 ? 'bad' : 'good'];
        };
        $countTrend = function (int $now, int $was) {
            if (! $was) return ['text' => $now ? 'в прошлом периоде не было' : 'нет данных за прошлый период', 'tone' => 'muted'];
            $d = ($now - $was) / $was * 100;
            if (abs($d) < 0.5) return ['text' => 'как в прошлом периоде', 'tone' => 'muted'];
            return ['text' => ($d > 0 ? '▲ +' : '▼ −') . round(abs($d)) . '% к прошлому (' . number_format($was, 0, ',', ' ') . ')', 'tone' => $d > 0 ? 'bad' : 'good'];
        };

        // ---------- рейтинги: доля от всего брака и ширина полосы ----------
        $rank = function (array $items) use ($defects) {
            $max = max(1, ...array_column($items, 'count') ?: [1]);
            return array_map(fn ($i) => $i + [
                'share' => $defects ? $i['count'] / $defects * 100 : 0,
                'w'     => max(2, round($i['count'] / $max * 100)),
            ], $items);
        };
        $codes   = $rank($data['codes']);
        $reasons = $rank($data['reasons']);
        $parts   = $rank($data['parts']);

        // ---------- вкладки и исполнители ----------
        $sheetRows = collect($data['sheets'])->map(fn ($s) => $s + ['rate' => $rate($s['defect'], $s['total'])])
            ->sortByDesc('defect');
        $workers = collect($data['workers'])->map(fn ($w) => $w + ['rate' => $rate($w['defect'], $w['total'])]);
        $maxRate = max(1, $sheetRows->max('rate') ?? 0, $workers->max('rate') ?? 0);

        // ---------- недели: столбики доли брака ----------
        $weeks = collect($data['weeks'])->map(fn ($w, $week) => $w + ['week' => $week, 'rate' => $rate($w['defect'], $w['total'])]);
        $maxWeekRate = max(0.1, $weeks->max('rate') ?? 0);
        $worstWeek = $weeks->filter(fn ($w) => $w['defect'] > 0)->sortByDesc('rate')->first();

        // ---------- «Главное за период» ----------
        $insights = [];
        if ($defects) {
            if ($top = $codes[0] ?? null) {
                $insights[] = ['Чаще всего — код ' . ($top['code'] ?? $top['text']), ($top['description'] ? '«' . $top['description'] . '», ' : '') . $num($top['count']) . ' ' . $plural($top['count'], 'случай', 'случая', 'случаев') . ' (' . $fmtPct($top['share'], 0) . ' всего брака)'];
            } elseif ($top = $reasons[0] ?? null) {
                $insights[] = ['Чаще всего — ' . mb_strtolower(mb_substr($top['text'], 0, 1)) . mb_substr($top['text'], 1), $num($top['count']) . ' ' . $plural($top['count'], 'случай', 'случая', 'случаев') . ' (' . $fmtPct($top['share'], 0) . ' всего брака)'];
            }
            if (count($codes) >= 3) {
                $top3 = array_sum(array_column(array_slice($codes, 0, 3), 'count'));
                $insights[] = ['Три самых частых кода — ' . $fmtPct($defects ? $top3 / $defects * 100 : 0, 0) . ' брака', 'Устранив их, можно убрать большую часть брака'];
            }
            if (! $sheet && ($worstSheet = $sheetRows->first()) && $worstSheet['defect'] > 0) {
                $insights[] = ['Больше всего брака — «' . $worstSheet['title'] . '»', $num($worstSheet['defect']) . ' из ' . $num($worstSheet['total']) . ' (' . $fmtPct($worstSheet['rate']) . ')'];
            }
            if ($worstWeek && $weeks->count() > 1) {
                $insights[] = ['Худшая неделя — с ' . \Carbon\Carbon::parse($worstWeek['week'])->format('d.m'), 'брак ' . $fmtPct($worstWeek['rate']) . ' (' . $num($worstWeek['defect']) . ' из ' . $num($worstWeek['total']) . ')'];
            }
            if (($uncoded = (int) ($data['uncoded'] ?? 0)) > 0) {
                $insights[] = ['Без кода неисправности — ' . $num($uncoded) . ' ' . $plural($uncoded, 'запись', 'записи', 'записей'), 'Причину по ним видно только из текста — проставьте коды, чтобы рейтинг был точнее'];
            }
        }

        // ---------- быстрые периоды ----------
        $preset = fn (int $d) => route('reports.defects', array_filter(['from' => now()->subDays($d - 1)->format('Y-m-d'), 'to' => now()->format('Y-m-d'), 'sheet' => $sheet]));
    @endphp

    <div class="py-6 max-w-6xl mx-auto px-4 sm:px-6 lg:px-8 space-y-4">

        {{-- Фильтр --}}
        <form method="GET" class="ui-card p-4 flex flex-wrap gap-3 items-end">
            <label class="block"><span class="ui-label">С</span><input type="date" name="from" value="{{ $from->format('Y-m-d') }}" class="ui-input"></label>
            <label class="block"><span class="ui-label">По</span><input type="date" name="to" value="{{ $to->format('Y-m-d') }}" class="ui-input"></label>
            <label class="block">
                <span class="ui-label">Вкладка</span>
                <select name="sheet" class="ui-input">
                    <option value="">Все вкладки участка</option>
                    @foreach($sheets as $slug => $cfg)
                        <option value="{{ $slug }}" @selected($sheet === $slug)>{{ $cfg['title'] }}</option>
                    @endforeach
                </select>
            </label>
            <button class="ui-btn ui-btn--primary">Показать</button>
            <div class="flex flex-wrap gap-1.5 ml-auto text-sm">
                @foreach([7 => 'Неделя', 30 => '30 дней', 90 => '3 месяца'] as $d => $label)
                    <a href="{{ $preset($d) }}" class="dr-chip {{ $days === $d && $to->isToday() ? 'dr-chip--active' : '' }}">{{ $label }}</a>
                @endforeach
            </div>
        </form>

        {{-- Показатели с изменением к прошлому периоду той же длины --}}
        @php
            $kpis = [
                ['Проверено записей', $num($data['total']), $countTrend((int) $data['total'], (int) $prev['total']), 'neutral'],
                ['Брак', $num($defects), $countTrend($defects, (int) $prev['defect']), 'bad'],
                ['Доля брака', $fmtPct($nowRate), $trend($delta), 'bad'],
            ];
        @endphp
        <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            @foreach($kpis as [$label, $value, $tr, $tone])
                <div class="ui-card p-4">
                    <div class="text-xs font-medium" style="color: var(--ui-muted)">{{ $label }}</div>
                    <div class="text-2xl font-bold mt-1 tabular-nums" style="{{ $tone === 'bad' && $defects ? 'color: var(--ui-bad)' : '' }}">{{ $value }}</div>
                    <div class="text-xs mt-1 dr-trend dr-trend--{{ $tr['tone'] }}">{{ $tr['text'] }}</div>
                </div>
            @endforeach
            <div class="ui-card p-4">
                <div class="text-xs font-medium" style="color: var(--ui-muted)">Главная причина</div>
                @if($topItem = $codes[0] ?? $reasons[0] ?? null)
                    <div class="text-lg font-bold mt-1 leading-tight {{ isset($topItem['code']) ? 'font-mono' : '' }}">{{ $topItem['code'] ?? $topItem['text'] }}</div>
                    @if(!empty($topItem['description']))<div class="text-xs mt-0.5 line-clamp-2" style="color: var(--ui-text-2)" title="{{ $topItem['description'] }}">{{ $topItem['description'] }}</div>@endif
                    <div class="text-xs mt-1" style="color: var(--ui-muted)">{{ $fmtPct($topItem['share'], 0) }} всего брака</div>
                @else
                    <div class="text-lg font-bold mt-1" style="color: var(--ui-faint)">—</div>
                @endif
            </div>
        </div>
        <p class="text-xs -mt-1" style="color: var(--ui-muted)">
            Сравнение — с периодом {{ $prev['from']->format('d.m.Y') }} — {{ $prev['to']->format('d.m.Y') }} ({{ $days }} {{ $plural($days, 'день', 'дня', 'дней') }}).
            Брак — записи со статусом «не сдан / неисправен», кодом неисправности или описанием дефекта.
        </p>

        @if(! $defects)
            <div class="ui-card p-8 text-center">
                <div class="text-base font-semibold" style="color: var(--ui-good)">За период брака нет</div>
                <div class="text-sm mt-1" style="color: var(--ui-muted)">Проверено записей: {{ $num($data['total']) }}</div>
            </div>
        @else
            {{-- Главное за период: выводы словами --}}
            @if($insights)
                <section class="ui-card p-4">
                    <h3 class="dr-title">Главное за период</h3>
                    <ul class="mt-2 grid gap-2.5 md:grid-cols-2">
                        @foreach($insights as [$head, $sub])
                            <li class="dr-insight">
                                <div class="text-sm font-semibold" style="color: var(--ui-text)">{{ $head }}</div>
                                <div class="text-xs mt-0.5" style="color: var(--ui-text-2)">{{ $sub }}</div>
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endif

            <div class="grid gap-4 lg:grid-cols-2">
                {{-- Коды неисправностей: код + расшифровка, доля от всего брака --}}
                <section class="ui-card overflow-hidden">
                    <div class="dr-head">
                        <h3 class="dr-title">Коды неисправностей</h3>
                        <span class="dr-hint">по справочнику, похожие написания объединены</span>
                    </div>
                    @if($codes)
                        <ol class="dr-list">
                            @foreach($codes as $n => $i)
                                <li class="dr-item">
                                    <div class="dr-item__head">
                                        <span class="dr-item__rank">{{ $n + 1 }}</span>
                                        <div class="dr-item__label">
                                            @if($i['code'])
                                                <span class="font-mono font-semibold">{{ $i['code'] }}</span>
                                                @if($i['description'])<span class="dr-item__desc">{{ $i['description'] }}</span>@endif
                                                @if(!$i['known'])<span class="ui-badge-warn">нет в справочнике</span>@endif
                                            @else
                                                <span>{{ $i['text'] }}</span>
                                                <span class="ui-badge-warn" title="Вписан текст, похожего кода в справочнике нет">без кода</span>
                                            @endif
                                        </div>
                                        <span class="dr-item__num"><b>{{ $num($i['count']) }}</b><span>{{ $fmtPct($i['share'], 0) }}</span></span>
                                    </div>
                                    <div class="dr-item__bar"><span style="width: {{ $i['w'] }}%"></span></div>
                                    <div class="dr-item__meta">{{ implode(' · ', $i['sheets']) }}</div>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="dr-empty">Коды неисправностей не проставлены</p>
                    @endif
                </section>

                {{-- Причины, как их записали словами --}}
                <section class="ui-card overflow-hidden">
                    <div class="dr-head">
                        <h3 class="dr-title">Причины (как записали)</h3>
                        <span class="dr-hint">из поля «причина / дефект», одинаковый текст — одна строка</span>
                    </div>
                    @if($reasons)
                        <ol class="dr-list">
                            @foreach($reasons as $n => $i)
                                <li class="dr-item">
                                    <div class="dr-item__head">
                                        <span class="dr-item__rank">{{ $n + 1 }}</span>
                                        <div class="dr-item__label">{{ $i['text'] }}</div>
                                        <span class="dr-item__num"><b>{{ $num($i['count']) }}</b><span>{{ $fmtPct($i['share'], 0) }}</span></span>
                                    </div>
                                    <div class="dr-item__bar"><span style="width: {{ $i['w'] }}%"></span></div>
                                    <div class="dr-item__meta">{{ implode(' · ', $i['sheets']) }}</div>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="dr-empty">Причины не указаны</p>
                    @endif
                </section>

                {{-- Неисправные узлы --}}
                <section class="ui-card overflow-hidden">
                    <div class="dr-head">
                        <h3 class="dr-title">Неисправные узлы</h3>
                        <span class="dr-hint">отметки «не работает / неисправен» в полях проверки</span>
                    </div>
                    @if($parts)
                        <ol class="dr-list">
                            @foreach($parts as $n => $i)
                                <li class="dr-item">
                                    <div class="dr-item__head">
                                        <span class="dr-item__rank">{{ $n + 1 }}</span>
                                        <div class="dr-item__label">{{ $i['text'] }}</div>
                                        <span class="dr-item__num"><b>{{ $num($i['count']) }}</b><span>{{ $fmtPct($i['share'], 0) }}</span></span>
                                    </div>
                                    <div class="dr-item__bar"><span style="width: {{ $i['w'] }}%"></span></div>
                                    <div class="dr-item__meta">{{ implode(' · ', $i['sheets']) }}</div>
                                </li>
                            @endforeach
                        </ol>
                    @else
                        <p class="dr-empty">Нет отметок «не работает»</p>
                    @endif
                </section>

                {{-- Динамика по неделям: столбик — доля брака за неделю --}}
                <section class="ui-card overflow-hidden">
                    <div class="dr-head">
                        <h3 class="dr-title">Доля брака по неделям</h3>
                        <span class="dr-hint">наведите на столбик — сколько из скольких</span>
                    </div>
                    @if($weeks->isNotEmpty())
                        <div class="dr-weeks" role="img" aria-label="Доля брака по неделям">
                            @foreach($weeks as $w)
                                @php $isWorst = $worstWeek && $w['week'] === $worstWeek['week'] && $weeks->count() > 1; @endphp
                                <div class="dr-week" data-tip="Неделя с {{ \Carbon\Carbon::parse($w['week'])->format('d.m.Y') }}: брак {{ $num($w['defect']) }} из {{ $num($w['total']) }} ({{ $fmtPct($w['rate']) }})" tabindex="0">
                                    <span class="dr-week__val">{{ $fmtPct($w['rate'], $w['rate'] !== null && $w['rate'] < 10 ? 1 : 0) }}</span>
                                    <span class="dr-week__track">
                                        <span class="dr-week__fill {{ $isWorst ? 'dr-week__fill--worst' : '' }}" style="height: {{ $w['rate'] ? max(3, round($w['rate'] / $maxWeekRate * 100)) : 0 }}%"></span>
                                    </span>
                                    <span class="dr-week__label">{{ \Carbon\Carbon::parse($w['week'])->format('d.m') }}</span>
                                    <span class="dr-week__count">{{ $num($w['defect']) }}/{{ $num($w['total']) }}</span>
                                </div>
                            @endforeach
                        </div>
                    @else
                        <p class="dr-empty">Нет записей за период</p>
                    @endif
                </section>
            </div>

            {{-- По вкладкам --}}
            @if(! $sheet)
                <section class="ui-card overflow-hidden">
                    <div class="dr-head">
                        <h3 class="dr-title">По вкладкам</h3>
                        <span class="dr-hint">нажмите на вкладку — отчёт только по ней</span>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="ui-table">
                            <thead><tr><th>Вкладка</th><th class="text-right">Записей</th><th class="text-right">Брак</th><th class="w-[38%]">Доля брака</th></tr></thead>
                            <tbody>
                                @foreach($sheetRows as $slug => $s)
                                    <tr>
                                        <td><a href="{{ route('reports.defects', ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d'), 'sheet' => $slug]) }}" class="font-medium" style="color: var(--ui-accent-text)">{{ $s['title'] }}</a></td>
                                        <td class="text-right tabular-nums">{{ $num($s['total']) }}</td>
                                        <td class="text-right tabular-nums" style="{{ $s['defect'] ? 'color: var(--ui-bad); font-weight: 600' : '' }}">{{ $num($s['defect']) }}</td>
                                        <td>
                                            <div class="dr-rate">
                                                <span class="dr-rate__track"><span class="dr-rate__fill" style="width: {{ $s['rate'] ? max(2, round($s['rate'] / $maxRate * 100)) : 0 }}%"></span></span>
                                                <span class="dr-rate__val">{{ $fmtPct($s['rate']) }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            @endif

            {{-- Исполнители с браком --}}
            <section class="ui-card overflow-hidden">
                <div class="dr-head">
                    <h3 class="dr-title">Исполнители с браком</h3>
                    <span class="dr-hint">средняя доля брака — {{ $fmtPct($nowRate) }}; «выше среднего» — в полтора раза больше и не меньше 2 случаев</span>
                </div>
                @if($workers->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="ui-table">
                            <thead><tr><th>Исполнитель</th><th class="text-right">Записей</th><th class="text-right">Брак</th><th class="w-[38%]">Доля брака</th></tr></thead>
                            <tbody>
                                @foreach($workers as $name => $w)
                                    @php $above = $nowRate && $w['rate'] !== null && $w['rate'] >= $nowRate * 1.5 && $w['defect'] >= 2; @endphp
                                    <tr>
                                        <td class="font-medium">
                                            {{ $name }}
                                            @if($above)<span class="dr-badge-bad">выше среднего</span>@endif
                                            @if(empty($w['known']))<span class="ui-badge-warn" title="Такого сотрудника нет в справочнике — проверьте написание в записях">нет в справочнике</span>@endif
                                            @if(!empty($w['variants']))<div class="text-xs font-normal" style="color: var(--ui-muted)">также записан: {{ implode(', ', array_slice($w['variants'], 0, 4)) }}@if(count($w['variants']) > 4) и ещё {{ count($w['variants']) - 4 }}@endif</div>@endif
                                        </td>
                                        <td class="text-right tabular-nums">{{ $num($w['total']) }}</td>
                                        <td class="text-right tabular-nums" style="color: var(--ui-bad); font-weight: 600">{{ $num($w['defect']) }}</td>
                                        <td>
                                            <div class="dr-rate">
                                                <span class="dr-rate__track"><span class="dr-rate__fill {{ $above ? 'dr-rate__fill--bad' : '' }}" style="width: {{ $w['rate'] ? max(2, round($w['rate'] / $maxRate * 100)) : 0 }}%"></span></span>
                                                <span class="dr-rate__val">{{ $fmtPct($w['rate']) }}</span>
                                            </div>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="dr-empty">Исполнители в записях с браком не указаны</p>
                @endif
            </section>
        @endif
    </div>
</x-app-layout>
