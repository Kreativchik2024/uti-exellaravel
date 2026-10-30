<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Кто работает за рабочим местом?</h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg shadow p-6">

            <p class="text-sm text-gray-600 mb-6">
                Выберите до 5 исполнителей. Если работаете на прогоне бортов — укажите,
                где вы находитесь: на посту (1–30) или на периферии (1–50).
            </p>

            <form method="POST" action="{{ route('workstation.store') }}">
                @csrf
                <input type="hidden" name="intended" value="{{ $intended }}">

                @php
                    $slots = old('workers', $selected ?: []);
                    $slots = array_pad(array_values($slots), 5, '');
                    $type  = old('type', $workplaceType);
                @endphp

                {{-- Исполнители --}}
                <div class="text-sm font-semibold text-gray-700 mb-2">Исполнители</div>
                @for($i = 0; $i < 5; $i++)
                    @php $current = $slots[$i] ?? ''; @endphp
                    <div class="mb-3">
                        <label class="block text-xs text-gray-600 mb-1">
                            Исполнитель {{ $i + 1 }}@if($i === 0)<span class="text-red-600">*</span>@endif
                        </label>
                        <select name="workers[]" class="border rounded px-3 py-2 w-full bg-white">
                            <option value="">— не выбран —</option>
                            @foreach(($employeesList ?? []) as $emp)
                                <option value="{{ $emp }}" @selected($current === $emp)>
                                    {{ $emp }}
                                </option>
                            @endforeach
                            @if($current !== '' && !in_array($current, $employeesList ?? [], true))
                                <option value="{{ $current }}" selected>{{ $current }} (не в справочнике)</option>
                            @endif
                        </select>
                        @error("workers.$i")
                            <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                @endfor

                {{-- Тип рабочего места --}}
                <div class="text-sm font-semibold text-gray-700 mt-6 mb-2">Рабочее место</div>

                <div class="flex items-center gap-6 mb-4">
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="type" value="post" id="type-post"
                               class="text-blue-600 focus:ring-blue-500"
                               @checked($type === 'post')>
                        <span class="text-sm text-gray-700">Пост</span>
                    </label>
                    <label class="inline-flex items-center gap-2 cursor-pointer">
                        <input type="radio" name="type" value="peripheral" id="type-peripheral"
                               class="text-blue-600 focus:ring-blue-500"
                               @checked($type === 'peripheral')>
                        <span class="text-sm text-gray-700">Периферия</span>
                    </label>
                </div>

                {{-- Пост --}}
                <div id="block-post" class="{{ $type === 'post' ? '' : 'hidden' }} mb-3">
                    <label class="block text-xs text-gray-600 mb-1">Пост (1–30)</label>
                    <select name="post" class="border rounded px-3 py-2 w-full bg-white">
                        <option value="">— не выбран —</option>
                        @foreach($posts as $p)
                            {{-- ВАЖНО: value — число, надпись — «Пост N» --}}
                            <option value="{{ $p }}" @selected((string) old('post', $selectedPost) === (string) $p)>
                                Пост {{ $p }}
                            </option>
                        @endforeach
                    </select>
                    @error('post')
                        <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
                    @enderror
                </div>

                {{-- Периферия --}}
                <div id="block-peripheral" class="{{ $type === 'peripheral' ? '' : 'hidden' }} mb-3">
                    <label class="block text-xs text-gray-600 mb-1">Периферия (1–50)</label>
                    <select name="peripheral" class="border rounded px-3 py-2 w-full bg-white">
                        <option value="">— не выбрана —</option>
                        @foreach($peripherals as $p)
                            <option value="{{ $p }}" @selected((string) old('peripheral', $selectedPeripheral) === (string) $p)>
                                Периферия {{ $p }}
                            </option>
                        @endforeach
                    </select>
                    @error('peripheral')
                        <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
                    @enderror
                </div>

                @if($errors->any())
                    <div class="bg-red-100 text-red-800 p-3 rounded mb-4">
                        @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                    </div>
                @endif

                <div class="flex flex-wrap gap-3 mt-6">
                    <button type="submit" class="bg-blue-600 hover:bg-blue-700 text-white px-6 py-2 rounded">
                        Продолжить
                    </button>
                    <button type="submit" form="skipForm" class="bg-gray-200 hover:bg-gray-300 text-gray-800 px-6 py-2 rounded">
                        Пропустить (без исполнителей)
                    </button>
                </div>
            </form>

            <form id="skipForm" method="POST" action="{{ route('workstation.skip') }}" class="hidden">
                @csrf
                <input type="hidden" name="intended" value="{{ $intended }}">
            </form>
        </div>
    </div>

    <script>
        (function () {
            var radios = document.querySelectorAll('input[name="type"]');
            var postBlock = document.getElementById('block-post');
            var peripheralBlock = document.getElementById('block-peripheral');

            function sync() {
                var val = document.querySelector('input[name="type"]:checked');
                val = val ? val.value : null;
                postBlock.classList.toggle('hidden', val !== 'post');
                peripheralBlock.classList.toggle('hidden', val !== 'peripheral');
            }

            radios.forEach(function (r) { r.addEventListener('change', sync); });
            sync();
        })();
    </script>
</x-app-layout>