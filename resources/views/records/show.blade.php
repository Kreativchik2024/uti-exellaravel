<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center gap-4">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('records.index', $sheet) }}"
                   class="flex-shrink-0 w-9 h-9 inline-flex items-center justify-center rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-600 transition active:scale-95">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                    </svg>
                </a>
                <div class="min-w-0">
                    <h2 class="font-bold text-2xl text-gray-900 tracking-tight truncate">{{ $cfg['title'] }}</h2>
                    <p class="text-sm text-gray-500 mt-0.5">Запись #{{ $record->id }}</p>
                </div>
            </div>
            <div class="flex gap-2 flex-shrink-0">
                @can('update', $record)
                    <a href="{{ route('records.edit', [$sheet, $record->id]) }}"
                       class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-medium px-4 py-2.5 rounded-lg shadow-sm hover:shadow-md transition-all duration-150 active:scale-95">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                        </svg>
                        Редактировать
                    </a>
                @endcan
                @can('delete', $record)
                    <form method="POST" action="{{ route('records.destroy', [$sheet, $record->id]) }}"
                          onsubmit="return confirm('Удалить запись?')">
                        @csrf @method('DELETE')
                        <button type="submit"
                                class="inline-flex items-center gap-2 bg-red-600 hover:bg-red-700 text-white font-medium px-4 py-2.5 rounded-lg shadow-sm hover:shadow-md transition-all duration-150 active:scale-95">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            Удалить
                        </button>
                    </form>
                @endcan
            </div>
        </div>
    </x-slot>

    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            @php
                $lookups = $lookups ?? [];
                $renderField = function ($name, $record, $f) {
                    $val = $record->$name;
                    $displayVal = $val;
                    $type = $f['type'] ?? 'text';

                    if ($type === 'select' && is_array($f['options'] ?? null) && array_key_exists($val, $f['options'])) {
                        $displayVal = $f['options'][$val];
                    } elseif ($val instanceof \Carbon\Carbon) {
                        $displayVal = $val->format('d.m.Y H:i');
                    } elseif (is_bool($val)) {
                        $displayVal = $val ? 'Да' : 'Нет';
                    } elseif ($name === 'record_date' && $val) {
                        $displayVal = \Carbon\Carbon::parse($val)->format('d.m.Y');
                    } elseif ($name === 'post_number' && $val) {
                        $displayVal = 'Пост ' . $val;
                    }

                    if ($displayVal === null || $displayVal === '') $displayVal = '—';
                    return ['displayVal' => $displayVal, 'type' => $type];
                };
            @endphp

            <dl class="divide-y divide-gray-100">
                @foreach($cfg['fields'] as $name => $f)
                    @php $r = $renderField($name, $record, $f); @endphp
                    <div class="px-6 py-4 {{ $r['type'] === 'textarea' ? '' : 'sm:flex sm:items-start sm:gap-6' }}">
                        <dt class="text-sm font-medium text-gray-500 {{ $r['type'] === 'textarea' ? 'mb-2' : 'sm:w-1/3 flex-shrink-0' }}">
                            {{ $f['label'] }}
                        </dt>
                        <dd class="text-gray-900 {{ $r['type'] === 'textarea' ? '' : 'sm:flex-1' }}">
                            @if($r['type'] === 'textarea')
                                <div class="whitespace-pre-line bg-gray-50 p-4 rounded-lg border border-gray-100 text-sm leading-relaxed">{{ $r['displayVal'] }}</div>
                            @else
                                <span class="text-sm">{{ $r['displayVal'] }}</span>
                            @endif

                            @if(!empty($f['lookup']))
                                @php $ld = $lookups[$name] ?? null; @endphp
                                @if($ld)
                                    @php
                                        $isGreen = ($ld['health'] ?? 'green') === 'green';
                                        $cls = $isGreen ? 'bg-green-50 border-green-300 text-green-800' : 'bg-red-50 border-red-300 text-red-800';
                                    @endphp
                                    <div class="mt-1">
                                        <div class="inline-flex items-center gap-2 px-3 py-1.5 rounded-lg border {{ $cls }} text-xs font-medium">
                                            <span>{{ $isGreen ? '✓' : '✗' }}</span>
                                            <span>{{ $ld['worker1'] ?? '—' }}</span>
                                            <span class="opacity-50">·</span>
                                            <span>{{ $ld['record_date'] ?? '—' }}</span>
                                            <span class="opacity-50">·</span>
                                            <span>{{ $ld['status_text'] ?? '' }}</span>
                                        </div>
                                    </div>
                                @else
                                    <div class="mt-1 text-xs text-gray-400">снимок отсутствует</div>
                                @endif
                            @endif
                        </dd>
                    </div>
                @endforeach
            </dl>

            <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex flex-wrap items-center justify-between gap-3 text-xs text-gray-500">
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>
                    </svg>
                    Создал: <span class="font-medium text-gray-700">{{ $record->creator?->name ?? 'Неизвестно' }}</span>
                </div>
                <div class="flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    {{ $record->created_at?->format('d.m.Y в H:i') }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>