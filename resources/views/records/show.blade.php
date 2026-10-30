<x-app-layout>
    <x-slot name="header">
        <div class="flex justify-between items-center gap-4">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('records.index', $sheet) }}"
                   class="flex-shrink-0 w-9 h-9 inline-flex items-center justify-center rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-600 transition active:scale-95"
                   title="Назад к списку">
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
                          onsubmit="return confirm('Вы уверены, что хотите удалить эту запись? Это действие нельзя отменить.')">
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
            <dl class="divide-y divide-gray-100">
                @foreach($cfg['fields'] as $name => $f)
                    @php
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
                        }

                        // Префиксы для отображения
                        if ($name === 'post_number' && $val) {
                            $displayVal = 'Пост ' . $val;
                        } elseif ($name === 'peripheral_number' && $val) {
                            $displayVal = 'Периферия ' . $val;
                        }

                        $show = fn($v) => ($v === null || $v === '') ? '—' : $v;
                    @endphp

                    <div class="px-6 py-4 {{ $type === 'textarea' ? '' : 'sm:flex sm:items-start sm:gap-6' }}">
                        <dt class="text-sm font-medium text-gray-500 {{ $type === 'textarea' ? 'mb-2' : 'sm:w-1/3 flex-shrink-0' }}">
                            {{ $f['label'] }}
                        </dt>
                        <dd class="text-gray-900 {{ $type === 'textarea' ? '' : 'sm:flex-1' }}">
                            @if($type === 'textarea')
                                <div class="whitespace-pre-line bg-gray-50 p-4 rounded-lg border border-gray-100 text-sm leading-relaxed">
                                    {{ $show($displayVal) }}
                                </div>
                            @elseif($name === 'is_repaired')
                                @if($val)
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium bg-green-100 text-green-700">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>
                                        </svg>
                                        Отремонтировано
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-sm font-medium bg-gray-100 text-gray-700">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z" clip-rule="evenodd"/>
                                        </svg>
                                        Не отремонтировано
                                    </span>
                                @endif
                            @else
                                <span class="text-sm">{{ $show($displayVal) }}</span>
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