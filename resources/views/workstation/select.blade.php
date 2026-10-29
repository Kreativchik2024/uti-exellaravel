<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Кто работает за рабочим местом?</h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg shadow p-6">

            <p class="text-sm text-gray-600 mb-6">
                Выберите до 5 исполнителей, которые сейчас работают за этим рабочим местом.
                Список — только из активных сотрудников справочника.
                Эти данные будут автоматически подставляться в формы учёта.
                Изменить выбор можно в любой момент через меню «Исполнители».
            </p>

            <form method="POST" action="{{ route('workstation.store') }}">
                @csrf
                <input type="hidden" name="intended" value="{{ $intended }}">

                @php
                    $slots = old('workers', $selected ?: []);
                    $slots = array_pad(array_values($slots), 5, '');
                @endphp

                @for($i = 0; $i < 5; $i++)
                    @php $current = $slots[$i] ?? ''; @endphp
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Исполнитель {{ $i + 1 }}
                        </label>
                        <select name="workers[]"
                                class="border rounded px-3 py-2 w-full bg-white">
                            <option value="">— не выбран —</option>
                            @foreach(($employeesList ?? []) as $emp)
                                <option value="{{ $emp }}" @selected($current === $emp)>
                                    {{ $emp }}
                                </option>
                            @endforeach

                            {{-- Если в сессии остался сотрудник, которого уже нет в справочнике
                                 (например, деактивирован), показываем его отдельной опцией,
                                 чтобы пользователь увидел выбор и мог заменить его. --}}
                            @if($current !== '' && !in_array($current, $employeesList ?? [], true))
                                <option value="{{ $current }}" selected>
                                    {{ $current }} (не в справочнике)
                                </option>
                            @endif
                        </select>
                        @error("workers.$i")
                            <div class="text-red-600 text-xs mt-1">{{ $message }}</div>
                        @enderror
                    </div>
                @endfor

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
</x-app-layout>