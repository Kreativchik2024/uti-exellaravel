@auth
{{-- Чат: общий канал + личные сообщения. Сервер — App\Http\Controllers\ChatController --}}
<div x-data="chatWidget({
        me: {{ auth()->id() }},
        urls: {
            summary: @js(route('chat.summary')),
            conversations: @js(route('chat.conversations')),
            users: @js(route('chat.users')),
            messages: @js(route('chat.messages')),
            send: @js(route('chat.send')),
            read: @js(route('chat.read')),
        },
     })"
     @keydown.escape.window="open && close()"
     class="ui-chat">

    {{-- Кнопка --}}
    <button type="button" class="ui-chat__fab" @click="toggle()" :aria-expanded="open"
            :aria-label="open ? 'Закрыть чат' : 'Открыть чат' + (unread ? ', непрочитанных: ' + unread : '')">
        <svg x-show="!open" class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/></svg>
        <svg x-show="open" x-cloak class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        <span x-show="unread > 0 && !open" x-cloak class="ui-chat__badge" x-text="unread > 99 ? '99+' : unread"></span>
    </button>

    {{-- Панель --}}
    <section x-show="open" x-cloak class="ui-chat__panel" role="dialog" aria-label="Чат"
             x-transition:enter="transition ease-out duration-200" x-transition:enter-start="opacity-0 translate-y-3 scale-[.98]" x-transition:enter-end="opacity-100 translate-y-0 scale-100"
             x-transition:leave="transition ease-in duration-150" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0 translate-y-2">

        {{-- ===== Список разговоров ===== --}}
        <template x-if="view === 'list'">
            <div class="ui-chat__screen">
                <header class="ui-chat__head">
                    <div class="flex-1">
                        <div class="ui-chat__title">Чат</div>
                        <div class="ui-chat__sub" x-text="conv.general.online ? 'онлайн: ' + conv.general.online : 'сообщения сотрудникам'"></div>
                    </div>
                    <button type="button" class="ui-iconbtn" @click="startNew()" title="Новое сообщение" aria-label="Новое сообщение">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </button>
                    <button type="button" class="ui-iconbtn" @click="close()" aria-label="Закрыть чат">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </header>

                <div class="ui-chat__scroll">
                    {{-- Общий канал --}}
                    <button type="button" class="ui-chat__conv" @click="openThread(0, 'Общий чат', false)">
                        <span class="ui-chat__avatar ui-chat__avatar--group">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                        </span>
                        <span class="ui-chat__conv-body">
                            <span class="ui-chat__conv-top">
                                <span class="ui-chat__conv-name">Общий чат</span>
                                <span class="ui-chat__conv-time" x-text="conv.general.last ? timeShort(conv.general.last.at) : ''"></span>
                            </span>
                            <span class="ui-chat__conv-bottom">
                                <span class="ui-chat__conv-last" x-text="conv.general.last ? (conv.general.last.author + ': ' + conv.general.last.body) : 'Сообщение всем сотрудникам'"></span>
                                <span x-show="conv.general.unread" class="ui-chat__count" x-text="conv.general.unread"></span>
                            </span>
                        </span>
                    </button>

                    <div class="ui-chat__section" x-show="conv.direct.length">Личные</div>
                    <template x-for="c in conv.direct" :key="c.peer">
                        <button type="button" class="ui-chat__conv" @click="openThread(c.peer, c.name, c.online)">
                            <span class="ui-chat__avatar">
                                <span x-text="c.initials"></span>
                                <span x-show="c.online" class="ui-chat__online-dot"></span>
                            </span>
                            <span class="ui-chat__conv-body">
                                <span class="ui-chat__conv-top">
                                    <span class="ui-chat__conv-name" x-text="c.name"></span>
                                    <span class="ui-chat__conv-time" x-text="timeShort(c.last.at)"></span>
                                </span>
                                <span class="ui-chat__conv-bottom">
                                    <span class="ui-chat__conv-last" x-text="(c.last.mine ? 'Вы: ' : '') + c.last.body"></span>
                                    <span x-show="c.unread" class="ui-chat__count" x-text="c.unread"></span>
                                </span>
                            </span>
                        </button>
                    </template>

                    <div x-show="!conv.direct.length" class="ui-chat__empty">
                        Личных переписок пока нет.
                        <button type="button" class="ui-chat__link" @click="startNew()">Написать сотруднику</button>
                    </div>
                </div>
            </div>
        </template>

        {{-- ===== Выбор собеседника ===== --}}
        <template x-if="view === 'new'">
            <div class="ui-chat__screen">
                <header class="ui-chat__head">
                    <button type="button" class="ui-iconbtn" @click="backToList()" aria-label="Назад">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <div class="ui-chat__title flex-1">Новое сообщение</div>
                </header>
                <div class="px-3 pb-2">
                    <label class="ui-search">
                        <span class="sr-only">Найти сотрудника</span>
                        <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                        <input type="text" x-model="search" @input.debounce.300ms="loadUsers()" x-ref="search"
                               placeholder="Фамилия или имя…" autocomplete="off">
                    </label>
                </div>
                <div class="ui-chat__scroll">
                    <template x-for="u in users" :key="u.peer">
                        <button type="button" class="ui-chat__conv" @click="openThread(u.peer, u.name, u.online)">
                            <span class="ui-chat__avatar">
                                <span x-text="u.initials"></span>
                                <span x-show="u.online" class="ui-chat__online-dot"></span>
                            </span>
                            <span class="ui-chat__conv-body">
                                <span class="ui-chat__conv-name" x-text="u.name"></span>
                                <span class="ui-chat__conv-last" x-text="u.online ? 'в сети' : 'не в сети'"></span>
                            </span>
                        </button>
                    </template>
                    <div x-show="!users.length" class="ui-chat__empty">Никого не найдено</div>
                </div>
            </div>
        </template>

        {{-- ===== Переписка ===== --}}
        <template x-if="view === 'thread'">
            <div class="ui-chat__screen">
                <header class="ui-chat__head">
                    <button type="button" class="ui-iconbtn" @click="backToList()" aria-label="Назад к списку">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <div class="flex-1 min-w-0">
                        <div class="ui-chat__title truncate" x-text="peerName"></div>
                        <div class="ui-chat__sub" x-text="peer === 0 ? 'все сотрудники' : (peerOnline ? 'в сети' : 'не в сети')"></div>
                    </div>
                    <button type="button" class="ui-iconbtn" @click="close()" aria-label="Закрыть чат">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </header>

                <div class="ui-chat__scroll ui-chat__messages" x-ref="list" @scroll.passive="onScroll()">
                    <div class="text-center py-2" x-show="hasMore">
                        <button type="button" class="ui-chat__link" @click="loadOlder()" :disabled="loadingOlder"
                                x-text="loadingOlder ? 'Загрузка…' : 'Показать ранние сообщения'"></button>
                    </div>

                    <template x-for="(m, i) in messages" :key="m.id">
                        <div>
                            <div x-show="showDay(i)" class="ui-chat__day"><span x-text="dayLabel(m.at)"></span></div>
                            <div class="ui-chat__msg" :class="m.mine ? 'ui-chat__msg--mine' : ''">
                                <div class="ui-chat__bubble">
                                    <div x-show="!m.mine && peer === 0 && showAuthor(i)" class="ui-chat__author" x-text="m.sender_name"></div>
                                    <div class="ui-chat__text" x-text="m.body"></div>
                                    <div class="ui-chat__time" x-text="timeOnly(m.at)"></div>
                                </div>
                            </div>
                        </div>
                    </template>

                    <div x-show="!messages.length && !loading" class="ui-chat__empty">
                        <span x-text="peer === 0 ? 'В общем чате пока тихо. Напишите первым!' : 'Начните переписку — сообщение увидит только собеседник.'"></span>
                    </div>
                </div>

                <form class="ui-chat__compose" @submit.prevent="send()">
                    <textarea x-ref="input" x-model="draft" rows="1" maxlength="2000"
                              placeholder="Сообщение…" aria-label="Текст сообщения"
                              @keydown.enter="if (!$event.shiftKey) { $event.preventDefault(); send(); }"
                              @input="autoGrow($event.target)"></textarea>
                    <button type="submit" class="ui-chat__send" :disabled="sending || !draft.trim()" aria-label="Отправить">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    </button>
                </form>
                <div x-show="error" x-text="error" class="ui-chat__error" role="alert"></div>
            </div>
        </template>
    </section>
</div>

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('chatWidget', (cfg) => ({
        me: cfg.me, urls: cfg.urls,
        open: false, view: 'list',
        unread: 0,
        conv: { general: { unread: 0, online: 0, last: null }, direct: [] },
        users: [], search: '',
        peer: 0, peerName: '', peerOnline: false,
        messages: [], hasMore: false, loading: false, loadingOlder: false,
        draft: '', sending: false, error: '',
        timer: null, ticks: 0, stickToBottom: true,

        init() {
            this.refreshSummary();
            this.timer = setInterval(() => this.tick(), 4000);
            document.addEventListener('visibilitychange', () => { if (!document.hidden) this.tick(true); });
        },

        // ---- сеть ----
        async api(url, opts = {}) {
            const res = await fetch(url, {
                credentials: 'same-origin',
                ...opts,
                headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(opts.headers || {}) },
            });
            const data = await res.json().catch(() => ({}));
            if (!res.ok) throw Object.assign(new Error(data.message || ('Ошибка ' + res.status)), { status: res.status });
            return data;
        },
        post(url, payload) {
            return this.api(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': window.csrfToken ? window.csrfToken() : '' },
                body: JSON.stringify(payload),
            });
        },
        withParams(url, params) { return url + '?' + new URLSearchParams(params).toString(); },

        // ---- опрос ----
        tick(force = false) {
            if (document.hidden && !force) return;
            this.ticks++;
            if (!this.open) {
                if (force || this.ticks % 5 === 0) this.refreshSummary();   // ~20 с
            } else if (this.view === 'thread') {
                this.fetchNew();                                           // ~4 с
                if (this.ticks % 5 === 0) this.refreshSummary();
            } else if (this.view === 'list') {
                if (force || this.ticks % 2 === 0) this.loadConversations(); // ~8 с
            }
        },
        async refreshSummary() {
            try { this.unread = (await this.api(this.urls.summary)).unread || 0; } catch (e) {}
        },
        async loadConversations() {
            try {
                this.conv = await this.api(this.urls.conversations);
                this.unread = (this.conv.general.unread || 0) + this.conv.direct.reduce((s, c) => s + (c.unread || 0), 0);
            } catch (e) {}
        },
        async loadUsers() {
            try { this.users = (await this.api(this.withParams(this.urls.users, { q: this.search }))).users; } catch (e) {}
        },

        // ---- навигация ----
        toggle() { this.open ? this.close() : this.openPanel(); },
        openPanel() { this.open = true; this.view = 'list'; this.loadConversations(); },
        close() { this.open = false; this.refreshSummary(); },
        backToList() { this.view = 'list'; this.messages = []; this.loadConversations(); },
        startNew() {
            this.view = 'new'; this.search = ''; this.loadUsers();
            this.$nextTick(() => this.$refs.search && this.$refs.search.focus());
        },
        async openThread(peer, name, online) {
            this.view = 'thread';
            this.peer = peer; this.peerName = name; this.peerOnline = online;
            this.messages = []; this.hasMore = false; this.error = ''; this.draft = '';
            this.loading = true;
            try {
                const data = await this.api(this.withParams(this.urls.messages, { peer }));
                this.messages = data.messages; this.hasMore = data.has_more;
                this.stickToBottom = true;
                this.scrollBottom();
                this.markRead();
            } catch (e) { this.error = e.message; }
            this.loading = false;
            this.$nextTick(() => this.$refs.input && this.$refs.input.focus());
        },

        // ---- сообщения ----
        lastId() { return this.messages.length ? this.messages[this.messages.length - 1].id : 0; },
        async fetchNew() {
            if (this.loading) return;
            try {
                const data = await this.api(this.withParams(this.urls.messages, { peer: this.peer, after: this.lastId() }));
                const known = new Set(this.messages.map(m => m.id));
                const fresh = data.messages.filter(m => !known.has(m.id));
                if (fresh.length) {
                    this.messages.push(...fresh);
                    if (this.stickToBottom) this.scrollBottom();
                    this.markRead();
                }
            } catch (e) {}
        },
        async loadOlder() {
            if (!this.messages.length) return;
            this.loadingOlder = true;
            const list = this.$refs.list, prevHeight = list ? list.scrollHeight : 0;
            try {
                const data = await this.api(this.withParams(this.urls.messages, { peer: this.peer, before: this.messages[0].id }));
                this.messages.unshift(...data.messages);
                this.hasMore = data.has_more;
                this.$nextTick(() => { if (list) list.scrollTop = list.scrollHeight - prevHeight; });
            } catch (e) {}
            this.loadingOlder = false;
        },
        async send() {
            const body = this.draft.trim();
            if (!body || this.sending) return;
            this.sending = true; this.error = '';
            try {
                const data = await this.post(this.urls.send, { peer: this.peer, body });
                if (!this.messages.some(m => m.id === data.message.id)) this.messages.push(data.message);
                this.draft = '';
                this.$nextTick(() => { if (this.$refs.input) { this.$refs.input.style.height = ''; this.$refs.input.focus(); } });
                this.stickToBottom = true; this.scrollBottom();
            } catch (e) {
                this.error = e.status === 429 ? 'Слишком часто — подождите немного.' : e.message;
            }
            this.sending = false;
        },
        markRead() {
            const id = this.lastId();
            if (id) this.post(this.urls.read, { peer: this.peer, last_id: id }).catch(() => {});
        },

        // ---- прокрутка и ввод ----
        onScroll() {
            const el = this.$refs.list; if (!el) return;
            this.stickToBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
        },
        scrollBottom() { this.$nextTick(() => { const el = this.$refs.list; if (el) el.scrollTop = el.scrollHeight; }); },
        autoGrow(el) { el.style.height = 'auto'; el.style.height = Math.min(el.scrollHeight, 120) + 'px'; },

        // ---- форматирование ----
        showDay(i) { return i === 0 || this.messages[i].at.slice(0, 10) !== this.messages[i - 1].at.slice(0, 10); },
        showAuthor(i) { return i === 0 || this.messages[i - 1].sender_id !== this.messages[i].sender_id || this.showDay(i); },
        dayLabel(iso) {
            const d = new Date(iso), today = new Date();
            const yest = new Date(); yest.setDate(today.getDate() - 1);
            if (d.toDateString() === today.toDateString()) return 'Сегодня';
            if (d.toDateString() === yest.toDateString()) return 'Вчера';
            return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long' });
        },
        timeOnly(iso) { return iso ? new Date(iso).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' }) : ''; },
        timeShort(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            return d.toDateString() === new Date().toDateString()
                ? this.timeOnly(iso)
                : d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' });
        },
    }));
});
</script>
@endauth
