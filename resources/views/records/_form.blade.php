@csrf
@if(isset($record))
    @method('PUT')
@endif

@php
    $workerFields = ['worker1','worker2','worker3','worker4','worker5'];
    $hasWorkers   = collect($workerFields)->contains(fn($f) => isset($cfg['fields'][$f]));
    // Значения из сессии "workstation_workers", переданные RecordController::create
    $workerDefaults = $defaults ?? [];
@endphp

@if($hasWorkers)
    <div class="border rounded p-3 bg-gray-50">
        <div class="text-sm font-semibold text-gray-700 mb-2">Исполнители (до 5, первый обязателен)</div>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-2">
            @foreach($workerFields as $name)
                @if(isset($cfg['fields'][$name]))
                    @php
                        $f     = $cfg['fields'][$name];
                        // Приоритет: old() → значение записи (edit) → значение из сессии (create)
                        $value = old($name, $record->$name ?? ($workerDefaults[$name] ?? null));
                        $isReq = !empty($f['required_mark']);
                    @endphp
                    <div>
                        <label class="block text-xs text-gray-600 mb-1">
                            {{ $f['label'] }}@if($isReq)<span class="text-red-600">*</span>@endif
                        </label>
                        <input type="text" name="{{ $name }}" value="{{ $value }}"
                               @if($isReq) required @endif
                               class="w-full border rounded px-2 py-1 text-sm">
                    </div>
                @endif
            @endforeach
        </div>
    </div>
@endif

@foreach($cfg['fields'] as $name => $f)
    @if(in_array($name, $workerFields)) @continue @endif

    @php
        $value = old($name, $record->$name ?? null);
        $isReq = str_contains($f['rules'], 'required') && !str_contains($f['rules'], 'nullable');
    @endphp
    <div>
        <label class="block text-sm text-gray-600 mb-1">
            {{ $f['label'] }}@if($isReq)<span class="text-red-600">*</span>@endif
        </label>

        @if($f['type'] === 'textarea')
            <textarea name="{{ $name }}" rows="3" @if($isReq) required @endif
                      class="w-full border rounded px-3 py-2">{{ $value }}</textarea>

        @elseif($f['type'] === 'select')
            <select name="{{ $name }}" @if($isReq) required @endif
                    class="w-full border rounded px-3 py-2">
                <option value="">— выберите —</option>
                @foreach($f['options'] as $opt)
                    <option value="{{ $opt }}" @selected($value === $opt)>{{ $opt }}</option>
                @endforeach
            </select>

        @elseif($f['type'] === 'date')
            @php
                $dateDefault = $value
                    ? \Carbon\Carbon::parse($value)->format('Y-m-d')
                    : \Illuminate\Support\Carbon::now()->format('Y-m-d');
            @endphp
            <input type="date" name="{{ $name }}"
                   value="{{ $dateDefault }}"
                   @if($isReq) required @endif
                   class="w-full border rounded px-3 py-2">

        @elseif($f['type'] === 'time')
            @php
                $timeDefault = $value
                    ? \Carbon\Carbon::parse($value)->format('H:i')
                    : ($name === 'work_end' ? \Illuminate\Support\Carbon::now()->format('H:i') : '');
            @endphp
            <input type="time" name="{{ $name }}"
                   value="{{ $timeDefault }}"
                   @if($isReq) required @endif
                   class="w-full border rounded px-3 py-2">

        @elseif($f['type'] === 'number')
            <input type="number" name="{{ $name }}" value="{{ $value }}"
                   @if($isReq) required @endif
                   class="w-full border rounded px-3 py-2">

        @else
            <input type="text" name="{{ $name }}" value="{{ $value }}"
                   @if($isReq) required @endif
                   class="w-full border rounded px-3 py-2">
        @endif
    </div>
@endforeach