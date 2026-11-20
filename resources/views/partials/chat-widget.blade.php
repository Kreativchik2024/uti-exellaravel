@auth
{{-- Чат: общий канал + личные сообщения. Ответы, вложения, реакции, «печатает…».
     Сервер — App\Http\Controllers\ChatController. История хранится 7 дней. --}}
<div x-data="chatWidget({
        me: {{ auth()->id() }},
        reactions: @js(\App\Models\ChatMessage::REACTIONS),
        canPin: @js(auth()->user()->canManageContent()),
        games: @js(\App\Models\Setting::gamesEnabled()),
        mentionName: @js((string) \Illuminate\Support\Str::of(auth()->user()->employee?->short_name ?: auth()->user()->name)->trim()->before(' ')),
        urls: {
            panel: @js(route('chat.panel')),
            summary: @js(route('chat.summary')),
            conversations: @js(route('chat.conversations')),
            users: @js(route('chat.users')),
            messages: @js(route('chat.messages')),
            send: @js(route('chat.send')),
            read: @js(route('chat.read')),
            typing: @js(route('chat.typing')),
            react: @js(route('chat.react', ['message' => '__ID__'])),
            pin: @js(route('chat.pin', ['message' => '__ID__'])),
            groupCreate: @js(route('chat.groups.create')),
            groupShow: @js(route('chat.groups.show', ['group' => '__ID__'])),
            groupAdd: @js(route('chat.groups.members.add', ['group' => '__ID__'])),
            groupRemove: @js(route('chat.groups.members.remove', ['group' => '__ID__'])),
            groupRename: @js(route('chat.groups.rename', ['group' => '__ID__'])),
            battleInvite: @js(route('games.battleship.invite')),
        },
     })"
     @keydown.escape.window="lightbox ? (lightbox = null) : (open && close())"
     class="ui-chat">

    {{-- Кнопка --}}
    <button type="button" class="ui-chat__fab" @click="toggle()" :aria-expanded="open"
            :aria-label="open ? 'Закрыть чат' : 'Открыть чат' + (unread ? ', непрочитанных: ' + unread : '')">
        <svg x-show="!open" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
        <svg x-show="open" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        <span x-show="unread > 0 && !open" x-cloak class="ui-chat__badge" :class="mentions > 0 && 'ui-chat__badge--mention'"
              x-text="(mentions > 0 ? '@ ' : '') + (unread > 99 ? '99+' : unread)"></span>
    </button>

    {{-- Панель — подгружается при первом открытии (partials/chat-panel, chatWidget.ensurePanel) --}}
    <div data-chat-panel-host></div>

    {{-- Просмотр картинки --}}
    <div x-show="lightbox" x-cloak class="ui-chat__lightbox" @click.self="lightbox = null" role="dialog" aria-label="Просмотр изображения">
        <img :src="lightbox && lightbox.url" :alt="lightbox && lightbox.name" @click="lightbox = null">
        <div class="ui-chat__lightbox-bar">
            <span class="truncate" x-text="lightbox && lightbox.name"></span>
            <a x-show="lightbox && !String(lightbox.url).startsWith('blob:')" :href="lightbox && (lightbox.url + '?download=1')" class="ui-btn ui-btn--ghost ui-btn--sm">Скачать</a>
            <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="lightbox = null">Закрыть</button>
        </div>
    </div>
</div>

<script src="{{ asset('js/chat-widget.js') }}?v={{ filemtime(public_path('js/chat-widget.js')) }}"></script>
@endauth
