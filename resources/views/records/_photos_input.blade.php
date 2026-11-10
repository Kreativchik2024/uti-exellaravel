{{-- Фото к записи: выбор с превью, текущие фото с отметкой «удалить» (в форме редактирования). --}}
@php $photos = $photos ?? collect(); @endphp
<div class="mt-8" x-data="recordPhotos({{ \App\Models\RecordPhoto::MAX_PER_RECORD }}, {{ $photos->count() }})">
    <div class="flex items-center gap-2 mb-3">
        <svg class="w-5 h-5" style="color: var(--ui-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 9a2 2 0 012-2h.93a2 2 0 001.664-.89l.812-1.22A2 2 0 0110.07 4h3.86a2 2 0 011.664.89l.812 1.22A2 2 0 0018.07 7H19a2 2 0 012 2v9a2 2 0 01-2 2H5a2 2 0 01-2-2V9z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 13a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
        <h3 class="font-semibold" style="color: var(--ui-text)">Фото</h3>
        <span class="text-xs" style="color: var(--ui-muted)" x-text="'до ' + max + ' шт., JPG / PNG до 15 МБ'"></span>
    </div>

    <div class="ui-photos">
        {{-- Уже прикреплённые (редактирование) --}}
        @foreach($photos as $p)
            <label class="ui-photos__item" :class="removed.includes({{ $p->id }}) && 'is-removed'" title="{{ $p->original_name }}">
                <img src="{{ $p->url(true) }}" alt="" loading="lazy">
                <input type="checkbox" name="delete_photos[]" value="{{ $p->id }}" class="hidden" @change="toggleRemoved({{ $p->id }}, $event.target.checked)" @disabled($isTimeLocked)>
                @unless($isTimeLocked)
                    <span class="ui-photos__del" x-text="removed.includes({{ $p->id }}) ? '↺' : '✕'" :title="removed.includes({{ $p->id }}) ? 'Не удалять' : 'Удалить фото'"></span>
                @endunless
                <span x-show="removed.includes({{ $p->id }})" class="ui-photos__badge">будет удалено</span>
            </label>
        @endforeach

        {{-- Новые — превью до сохранения --}}
        <template x-for="(f, i) in previews" :key="f.url">
            <div class="ui-photos__item ui-photos__item--new" :title="f.name">
                <img :src="f.url" alt="">
                <button type="button" class="ui-photos__del" @click="remove(i)" title="Убрать">✕</button>
                <span class="ui-photos__badge ui-photos__badge--new">новое</span>
            </div>
        </template>

        @unless($isTimeLocked)
            <button type="button" class="ui-photos__add" x-show="total() < max" @click="$refs.input.click()">
                <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                <span>Добавить фото</span>
            </button>
        @endunless
    </div>
    <input type="file" name="photos[]" accept="image/*" multiple capture="environment" class="hidden" x-ref="input" @change="add($event)">
    <p x-show="error" x-text="error" class="mt-2 text-xs" style="color: var(--ui-bad)"></p>
    @error('photos')<p class="mt-2 text-xs" style="color: var(--ui-bad)">{{ $message }}</p>@enderror
    @error('photos.*')<p class="mt-2 text-xs" style="color: var(--ui-bad)">{{ $message }}</p>@enderror
</div>

@once
<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('recordPhotos', (max, existing) => {
        let files = [];   // выбранные File — вне реактивных данных (прокси Alpine нельзя положить в DataTransfer)
        return {
            max, existing, previews: [], removed: [], error: '',
            total() { return this.existing - this.removed.length + this.previews.length; },
            add(e) {
                this.error = '';
                for (const f of Array.from(e.target.files || [])) {
                    if (!f.type.startsWith('image/')) { this.error = 'Можно прикреплять только фото.'; continue; }
                    if (f.size > 15 * 1024 * 1024) { this.error = 'Фото «' + f.name + '» больше 15 МБ.'; continue; }
                    if (this.total() >= this.max) { this.error = 'Не больше ' + this.max + ' фото.'; break; }
                    files.push(f);
                    this.previews.push({ url: URL.createObjectURL(f), name: f.name });
                }
                this.sync();
            },
            remove(i) {
                URL.revokeObjectURL(this.previews[i].url);
                files.splice(i, 1);
                this.previews.splice(i, 1);
                this.sync();
            },
            toggleRemoved(id, on) { this.removed = on ? [...this.removed, id] : this.removed.filter(x => x !== id); },
            // Поле файла отправляет ровно те фото, что видны в превью
            sync() {
                const dt = new DataTransfer();
                files.forEach(f => dt.items.add(f));
                this.$refs.input.files = dt.files;
            },
        };
    });
});
</script>
@endonce
