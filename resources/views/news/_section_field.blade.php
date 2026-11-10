{{-- Участок новости. «Для всех участков» (пусто) — только администратор. --}}
@php
    $selectedSection = old('section_id', isset($news) ? ($news->section_id ?? 'all') : ($currentSection?->id ?? ''));
@endphp
<div>
    <label class="block text-sm text-gray-600 mb-1" for="news-section">Где показывать</label>
    <select id="news-section" name="section_id" class="w-full sm:max-w-sm border rounded px-3 py-2 text-sm">
        @if(auth()->user()->isAdmin())
            <option value="all" @selected((string) $selectedSection === 'all')>Для всех участков</option>
        @endif
        @foreach($sections as $s)
            <option value="{{ $s->id }}" @selected((string) $selectedSection === (string) $s->id)>{{ $s->name }}</option>
        @endforeach
    </select>
    @error('section_id')<div class="text-xs text-red-600 mt-1">{{ $message }}</div>@enderror
</div>
