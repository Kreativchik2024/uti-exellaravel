<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">Кто работает за рабочим местом?</h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <div class="bg-white rounded-lg shadow p-6">

            {{-- Общий datalist для всех 5 полей --}}
            <datalist id="employees-list">
                @foreach(($employeesList ?? []) as $emp)
                    <option value="{{ $emp }}"></option>
                @endforeach
            </datalist>

            <p class="text-sm text-gray-600 mb-6">
                Впишите до 5 исполнителей, которые сейчас работают за этим рабочим местом.
                Начните печатать фамилию — браузер предложит сотрудников из справочника
                (или впишите вручную, если сотрудника ещё нет в списке).
                Эти данные будут автоматически подставляться в формы учёта.
                Изменить выбор можно в любой момент через меню «Исполнители».
            </p>

            <form method="POST" action="{{ route('workstation.store') }}">
                @csrf
                <input type="hidden" name="intended" value="{{ $intended }}">

                @for($i = 0; $i < 5; $i++)
                    <div class="mb-4">
                        <label class="block text-sm font-medium text-gray-700 mb-1">
                            Исполнитель {{ $i + 1 }}
                        </label>
                        <input type="text"
                               name="workers[]"
                               value="{{ old('workers.' . $i, $selected[$i] ?? '') }}"
                               list="employees-list"
                               autocomplete="off"
                               placeholder="Фамилия И.О. (начните печатать для подсказки)"
                               class="border rounded px-3 py-2 w-full bg-white">
                    </div>
                @endfor

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