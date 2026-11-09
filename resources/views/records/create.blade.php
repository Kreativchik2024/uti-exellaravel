<x-app-layout>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <a href="{{ route('records.index', $sheet) }}"
               class="flex-shrink-0 w-10 h-10 inline-flex items-center justify-center rounded-xl bg-gray-100 hover:bg-gray-200 text-gray-600 transition active:scale-95"
               title="Назад к списку">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
                </svg>
            </a>
            <div>
                <h2 class="font-bold text-2xl text-gray-900 tracking-tight">Новая запись</h2>
                <p class="text-sm text-gray-500 mt-0.5">{{ $cfg['title'] }}</p>
            </div>
        </div>
    </x-slot>

    <div class="py-8 max-w-4xl mx-auto px-4 sm:px-6 lg:px-8">
          
        <form method="POST" action="{{ route('records.store', $sheet) }}"
              class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">

            @error('general')
                <div class="mx-6 mt-6 sm:mx-8 sm:mt-8 bg-red-50 border border-red-200 text-red-800 px-4 py-3 rounded-xl text-sm font-medium">
                    {{ $message }}
                </div>
            @enderror

            @include('records._form')

        </form>
    </div>

    @include('records._worker_pin_modal')
</x-app-layout>