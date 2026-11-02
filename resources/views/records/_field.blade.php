@php
    /** @var string $name */
    /** @var array $f */
    $value = old($name, $record->{$name} ?? ($defaults[$name] ?? ($f['default'] ?? null)));
    $isReq = str_contains($f['rules'] ?? '', 'required') && !str_contains($f['rules'] ?? '', 'nullable');
    $hasLookup = !empty($f['lookup']);
    $lookupData = $lookups[$name] ?? null;
    $label = $f['label'] ?? $name;
    $type = $f['type'] ?? 'text';

    $baseInput = 'block w-full rounded-lg border bg-white px-3.5 py-2.5 text-sm text-gray-900 placeholder-gray-400 shadow-sm transition-all duration-150 '
               . 'focus:outline-none focus:ring-4 focus:ring-blue-500/15 focus:border-blue-500 '
               . 'border-gray-200 hover:border-gray-300';

    if ($isTimeLocked) {
        $baseInput = 'block w-full rounded-lg border bg-gray-50 px-3.5 py-2.5 text-sm text-gray-500 cursor-not-allowed border-gray-200';
    }
@endphp

<div class="{{ $isTimeLocked ? 'opacity-80' : '' }}">
    <label class="flex items-center gap-1.5 text-sm font-medium text-gray-700 mb-1.5">
        {{ $label }}
        @if($isReq && !$isTimeLocked)
            <span class="inline-flex items-center px-1.5 py-0.5 rounded bg-red-50 text-red-600 text-[10px] font-semibold uppercase tracking-wide">Обязательно</span>
        @endif
    </label>

    @if($hasLookup)
        <div class="lookup-result mb-1.5">
            @if($lookupData)
                @php
                    $isGreen = ($lookupData['health'] ?? 'green') === 'green';
                    $cls = $isGreen
                        ? 'bg-emerald-50 border-emerald-200 text-emerald-800'
                        : 'bg-red-50 border-red-200 text-red-800';
                    $icon = $isGreen ? '✓' : '✗';
                @endphp
                <div class="inline-flex items-center gap-2 px-2.5 py-1 rounded-md border {{ $cls }} text-xs font-medium">
                    <span class="font-bold">{{ $icon }}</span>
                    <span>{{ $lookupData['worker1'] ?? '—' }}</span>
                    <span class="opacity-50">·</span>
                    <span>{{ $lookupData['record_date'] ?? '—' }}</span>
                    <span class="opacity-50">·</span>
                    <span>{{ $lookupData['status_text'] ?? '' }}</span>
                </div>
            @endif
        </div>

        <input type="text" name="{{ $name }}"
               value="{{ $value }}"
               @if($isReq) required @endif
               @if($isTimeLocked) disabled @endif
               data-lookup-field="{{ $name }}"
               data-lookup-url="{{ route('records.lookup', $sheet) }}"
               placeholder="Введите {{ mb_strtolower($label) }}…"
               class="lookup-input {{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">

    @elseif($type === 'textarea')
        <textarea name="{{ $name }}" rows="3"
                  @if($isReq) required @endif @if($isTimeLocked) disabled @endif
                  placeholder="{{ $f['placeholder'] ?? '' }}"
                  class="{{ $baseInput }} resize-none @error($name) !border-red-400 !ring-red-500/15 @enderror">{{ $value }}</textarea>

    @elseif($type === 'select')
        <select name="{{ $name }}" @if($isReq) required @endif @if($isTimeLocked) disabled @endif
                class="{{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">
            <option value="">— выберите —</option>
            @foreach(($f['options'] ?? []) as $optKey => $optVal)
                @php
                    $optValue = is_string($optKey) ? $optKey : $optVal;
                    $optLabel = is_string($optKey) ? $optVal : $optVal;
                @endphp
                <option value="{{ $optValue }}" @selected((string)$value === (string)$optValue)>{{ $optLabel }}</option>
            @endforeach
        </select>

    @elseif($type === 'date')
        <input type="date" name="{{ $name }}"
               value="{{ $value ? \Carbon\Carbon::parse($value)->format('Y-m-d') : \Illuminate\Support\Carbon::now()->format('Y-m-d') }}"
               @if($isReq) required @endif @if($isTimeLocked) disabled @endif
               class="{{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">

    @elseif($type === 'time')
        <input type="time" name="{{ $name }}"
               value="{{ $value ? \Carbon\Carbon::parse($value)->format('H:i') : '' }}"
               @if($isReq) required @endif @if($isTimeLocked) disabled @endif
               class="{{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">

    @elseif($type === 'number')
        <input type="number" name="{{ $name }}" value="{{ $value }}"
               @if($isReq) required @endif @if($isTimeLocked) disabled @endif
               class="{{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">

    @else
        <input type="text" name="{{ $name }}" value="{{ $value }}"
               @if($isReq) required @endif @if($isTimeLocked) disabled @endif
               placeholder="{{ $f['placeholder'] ?? '' }}"
               class="{{ $baseInput }} @error($name) !border-red-400 !ring-red-500/15 @enderror">
    @endif

    @error($name)
        <p class="mt-1.5 text-xs text-red-600 flex items-center gap-1">
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7 4a1 1 0 11-2 0 1 1 0 012 0zm-1-9a1 1 0 00-1 1v4a1 1 0 102 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/>
            </svg>
            {{ $message }}
        </p>
    @enderror
</div>