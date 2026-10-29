<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800">{{ $cfg['title'] }} — редактирование</h2>
    </x-slot>

    <div class="py-8 max-w-3xl mx-auto sm:px-6 lg:px-8">
        <form method="POST" action="{{ route('records.update', [$sheet, $record->id]) }}"
              class="bg-white p-6 rounded shadow space-y-4">
              @csrf
            @include('records._form', ['record' => $record])

            @if($errors->any())
                <div class="bg-red-100 text-red-800 p-3 rounded">
                    @foreach($errors->all() as $e)<div>{{ $e }}</div>@endforeach
                </div>
            @endif

            <div class="flex gap-2">
                <button class="bg-blue-600 text-white px-4 py-2 rounded">Сохранить</button>
                <a href="{{ route('records.index', $sheet) }}" class="px-4 py-2 text-gray-600">Отмена</a>
            </div>
        </form>
    </div>
</x-app-layout>