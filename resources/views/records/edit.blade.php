<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('records.show', [$sheet, $record->id]) }}"
               class="flex-shrink-0 w-9 h-9 inline-flex items-center justify-center rounded-lg bg-gray-100 hover:bg-gray-200 text-gray-600 transition active:scale-95"
               title="Назад к карточке">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <h2 class="font-bold text-2xl text-gray-900 tracking-tight">Редактирование записи #{{ $record->id }}</h2>
                <p class="text-sm text-gray-500 mt-0.5">{{ $cfg['title'] }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-8 max-w-4xl mx-auto sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('records.update', [$sheet, $record->id]) }}"
              class="bg-white p-6 sm:p-8 rounded-xl shadow-sm border border-gray-100 space-y-6">
            @csrf
            @method('PUT')

            @if($errors->any())
                <div class="bg-red-50 border border-red-200 text-red-800 p-4 rounded-lg">
                    <p class="font-semibold mb-1">Проверьте поля:</p>
                    <ul class="list-disc list-inside text-sm space-y-0.5">
                        @foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach
                    </ul>
                </div>
            @endif

            @include('records._form')

            <div class="pt-6 border-t border-gray-100 flex items-center justify-end gap-3">
                <a href="{{ route('records.show', [$sheet, $record->id]) }}"
                   class="px-5 py-2.5 text-gray-700 hover:bg-gray-100 rounded-lg font-medium transition active:scale-95">
                    Отмена
                </a>
                <button type="submit"
                        class="inline-flex items-center gap-2 bg-blue-600 hover:bg-blue-700 text-white font-medium px-5 py-2.5 rounded-lg shadow-sm hover:shadow-md transition-all duration-150 active:scale-95">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/>
                    </svg>
                    Сохранить
                </button>
            </div>
        </form>
    </div>
</x-app-layout>