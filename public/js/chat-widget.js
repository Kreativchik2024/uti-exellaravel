/* Чат: Alpine-компонент chatWidget (разметка — resources/views/partials/chat-widget.blade.php). */
document.addEventListener('alpine:init', () => {
    Alpine.data('chatWidget', (cfg) => {
        // Файлы держим ВНЕ реактивного состояния: прокси Alpine нельзя положить в FormData
        const fileStore = new Map();
        let fileSeq = 0, lastTypingSent = 0, rootEl = null;

        return {
            me: cfg.me, urls: cfg.urls, reactionSet: cfg.reactions, canPin: cfg.canPin, mentionName: cfg.mentionName,
            games: cfg.games, inviting: false,
            mentions: 0, pinned: null, mentionList: [], mentionIdx: 0, mentionTimer: null,
            notifyOn: false, notifyAfter: null, notifySupported: 'Notification' in window && window.isSecureContext, hiddenTicks: 0,
            open: false, view: 'list',
            unread: 0,
            conv: { general: { unread: 0, online: 0, last: null }, groups: [], direct: [] },
            users: [], search: '',
            peer: 0, peerName: '', peerOnline: false,
            group: 0, groupMembers: 0, groupInfo: null,
            pick: { mode: 'create', name: '', selected: [], busy: false },
            messages: [], hasMore: false, loading: false, loadingOlder: false,
            draft: '', error: '',
            peerReadId: 0, generalReads: [], tmpSeq: 0,
            firstUnreadId: null, since: null, typingNames: [],
            replyTo: null, pendingFiles: [], actionsFor: null, flashId: null, bump: null,
            lightbox: null, dragOver: false, _panelLoading: null,
            timer: null, ticks: 0, stickToBottom: true,

            init() {
                // Корень виджета. $refs в обработчиках, вызванных из исчезающих
                // элементов (строка списка → переписка), ищет не там — поэтому так.
                rootEl = this.$el;
                try {
                    this.notifyOn = localStorage.getItem('uti-chat-notify:' + this.me) === '1' && this.notifySupported && Notification.permission === 'granted';
                    const after = parseInt(localStorage.getItem('uti-chat-notified:' + this.me), 10);
                    this.notifyAfter = after > 0 ? after : null;
                } catch (e) {}
                this.refreshSummary();
                this.timer = setInterval(() => this.tick(), 4000);
                document.addEventListener('visibilitychange', () => { if (!document.hidden) this.tick(true); });
            },

            /** Элементы виджета по x-ref, найденные от корня (надёжнее $refs, см. init). */
            el(ref) { return rootEl ? rootEl.querySelector('[x-ref="' + ref + '"]') : null; },

            // ---- сеть ----
            async api(url, opts = {}) {
                const res = await fetch(url, {
                    credentials: 'same-origin',
                    ...opts,
                    headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(opts.headers || {}) },
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) {
                    const firstError = data.errors ? Object.values(data.errors)[0][0] : null;
                    throw Object.assign(new Error(firstError || data.message || ('Ошибка ' + res.status)), { status: res.status });
                }
                return data;
            },
            post(url, payload) {
                const isForm = payload instanceof FormData;
                return this.api(url, {
                    method: 'POST',
                    headers: Object.assign({ 'X-CSRF-TOKEN': window.csrfToken ? window.csrfToken() : '' },
                                           isForm ? {} : { 'Content-Type': 'application/json' }),
                    body: isForm ? payload : JSON.stringify(payload),
                });
            },
            withParams(url, params) { return url + '?' + new URLSearchParams(params).toString(); },
            /** Параметры текущего канала: группа или собеседник (0 — общий чат). */
            chan(extra = {}) { return Object.assign(this.group ? { group: this.group } : { peer: this.peer }, extra); },

            // ---- опрос ----
            tick(force = false) {
                if (document.hidden && !force) {
                    if (++this.hiddenTicks % 8 === 0) this.refreshSummary();   // ~30 с: уведомления при свёрнутой вкладке
                    return;
                }
                this.ticks++;
                if (!this.open) {
                    if (force || this.ticks % 5 === 0) this.refreshSummary();     // ~20 с
                } else if (this.view === 'thread') {
                    this.fetchNew();                                               // ~4 с
                    if (this.ticks % 5 === 0) this.refreshSummary();
                } else if (this.view === 'list') {
                    if (force || this.ticks % 2 === 0) this.loadConversations();  // ~8 с
                }
            },
            async refreshSummary() {
                try {
                    const params = this.notifyAfter ? { after: this.notifyAfter } : {};
                    const data = await this.api(this.withParams(this.urls.summary, params));
                    this.unread = data.unread || 0;
                    this.mentions = data.mentions || 0;
                    if (this.notifyAfter) (data.notify || []).forEach(n => this.showNotification(n));
                    this.notifyAfter = Math.max(this.notifyAfter || 0, data.last_id || 0);
                    try { localStorage.setItem('uti-chat-notified:' + this.me, String(this.notifyAfter)); } catch (e) {}
                } catch (e) {}
            },

            // ---- уведомления браузера: личные сообщения и упоминания ----
            async toggleNotifications() {
                if (!this.notifySupported) return;
                if (this.notifyOn) { this.notifyOn = false; }
                else {
                    const perm = Notification.permission === 'default' ? await Notification.requestPermission() : Notification.permission;
                    this.notifyOn = perm === 'granted';
                    if (perm === 'denied') this.error = 'Уведомления запрещены в настройках браузера для этого сайта';
                }
                try { localStorage.setItem('uti-chat-notify:' + this.me, this.notifyOn ? '1' : '0'); } catch (e) {}
            },
            showNotification(n) {
                // Уже открыт этот разговор и вкладка на экране — не мешаем
                if (!document.hidden && this.open && this.view === 'thread' && this.peer === n.peer && this.group === (n.group || 0)) return;
                if (!this.notifyOn || Notification.permission !== 'granted') return;
                try {
                    const title = n.group ? n.name + ' в группе «' + n.group_name + '»'
                                : n.mention ? n.name + ' упомянул(а) вас в общем чате' : 'Сообщение от ' + n.name;
                    const note = new Notification(title, {
                        body: n.preview, icon: '/icons/icon-192.png', tag: 'uti-chat-' + n.id,
                    });
                    note.onclick = async () => {
                        window.focus();
                        if (!(await this.ensurePanel())) return;
                        this.open = true;
                        if (n.group) this.openThread(0, n.group_name, false, n.group);
                        else this.openThread(n.peer, n.peer === 0 ? 'Общий чат' : n.name, false);
                        note.close();
                    };
                } catch (e) {}
            },

            // ---- @упоминания ----
            formatBody(text) {
                const escape = s => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
                const me = (this.mentionName || '').toLowerCase();
                const plain = s => escape(s)
                    .replace(/\/games\/battleship\/(\d+)/g, '<a href="/games/battleship/$1" class="ui-chat__game-link">Открыть игру</a>')
                    .replace(/(^|[\s(,.;:!?])@([\p{L}\-]{2,40})/gu, (all, pre, name) => {
                        const mine = name.toLowerCase() === me || name.toLowerCase() === 'все';
                        return pre + '<span class="ui-chat__mention' + (mine ? ' ui-chat__mention--me' : '') + '">@' + name + '</span>';
                    });
                // Ссылки: http(s)://… и www.… — кликабельные, открываются в новой вкладке.
                // Знаки препинания в конце («…/page.», «(…/page)») к ссылке не относятся.
                const url = /(?:https?:\/\/|www\.)[^\s<>"']+/giu;
                let out = '', last = 0, m;
                const src = String(text);
                while ((m = url.exec(src))) {
                    let link = m[0];
                    // хвостовая пунктуация и непарные «)» — не часть ссылки (а «…/a_(b)» — часть)
                    for (;;) {
                        const t = /[.,;:!?\]}»"']+$/u.exec(link);
                        if (t) { link = link.slice(0, -t[0].length); continue; }
                        if (link.endsWith(')') && link.split(')').length > link.split('(').length) { link = link.slice(0, -1); continue; }
                        break;
                    }
                    if (!link) continue;
                    const href = /^www\./i.test(link) ? 'https://' + link : link;
                    const game = /^https?:\/\/[^/]+\/games\/battleship\/(\d+)\/?$/i.exec(href);
                    out += plain(src.slice(last, m.index)) + (game && href.startsWith(location.origin)
                        ? '<a href="/games/battleship/' + game[1] + '" class="ui-chat__game-link">Открыть игру</a>'
                        : '<a href="' + escape(href) + '" class="ui-chat__url" target="_blank" rel="noopener noreferrer nofollow">' + escape(link) + '</a>');
                    last = m.index + link.length;
                    url.lastIndex = last;
                }
                return out + plain(src.slice(last));
            },
            onMentionInput(t) {
                const m = /(^|\s)@([\p{L}\-]{0,30})$/u.exec(t.value.slice(0, t.selectionStart));
                clearTimeout(this.mentionTimer);
                if (!m) { this.mentionList = []; return; }
                const q = m[2];
                this.mentionTimer = setTimeout(async () => {
                    try {
                        const users = (await this.api(this.withParams(this.urls.users, { q }))).users || [];
                        const list = users.slice(0, 6);
                        if (this.peer === 0 && !this.group && 'все'.startsWith(q.toLowerCase())) list.unshift({ peer: -1, name: 'все', initials: '@', online: false });
                        this.mentionList = list;
                        this.mentionIdx = 0;
                    } catch (e) { this.mentionList = []; }
                }, 200);
            },
            pickMention(u) {
                const t = this.el('input');
                if (!t || !u) return;
                const surname = u.peer === -1 ? 'все' : String(u.name).trim().split(/\s+/)[0];
                const before = t.value.slice(0, t.selectionStart).replace(/@[\p{L}\-]*$/u, '@' + surname + ' ');
                this.draft = t.value = before + t.value.slice(t.selectionStart);
                this.mentionList = [];
                this.$nextTick(() => { t.focus(); t.setSelectionRange(before.length, before.length); });
            },

            // ---- морской бой с собеседником ----
            async inviteBattle() {
                if (this.peer === 0 || this.inviting) return;
                this.inviting = true;
                try {
                    const d = await this.post(this.urls.battleInvite, { user_id: this.peer });
                    if (d.url) window.location.href = d.url;
                } catch (e) { this.error = e.message; }
                this.inviting = false;
            },

            // ---- закреплённое сообщение ----
            async togglePin(m) {
                this.actionsFor = null;
                if (!m || typeof m.id !== 'number') return;
                try { this.pinned = (await this.post(this.urls.pin.replace('__ID__', m.id), {})).pinned; }
                catch (e) { this.error = e.message; }
            },
            async loadConversations() {
                try {
                    this.conv = await this.api(this.urls.conversations);
                    this.conv.groups = this.conv.groups || [];
                    this.unread = (this.conv.general.unread || 0)
                        + this.conv.direct.reduce((s, c) => s + (c.unread || 0), 0)
                        + this.conv.groups.reduce((s, c) => s + (c.unread || 0), 0);
                } catch (e) {}
            },

            // ---- группы ----
            startPick(mode) {
                this.view = 'pick'; this.error = ''; this.search = '';
                this.pick = { mode, name: '', selected: [], busy: false };
                this.loadUsers();
                this.$nextTick(() => { const el = this.el(mode === 'create' ? 'groupName' : 'search'); el && el.focus(); });
            },
            /** Кого можно отметить: уже состоящих в группе не показываем. */
            pickable() {
                const inGroup = new Set(this.pick.mode === 'add' && this.groupInfo ? this.groupInfo.members.map(m => m.peer) : []);
                return this.users.filter(u => !inGroup.has(u.peer));
            },
            isPicked(u) { return this.pick.selected.some(x => x.peer === u.peer); },
            togglePick(u) {
                this.pick.selected = this.isPicked(u)
                    ? this.pick.selected.filter(x => x.peer !== u.peer)
                    : [...this.pick.selected, { peer: u.peer, name: u.name }];
            },
            async submitPick() {
                if (this.pick.busy || !this.pick.selected.length) return;
                this.pick.busy = true; this.error = '';
                const ids = this.pick.selected.map(u => u.peer);
                try {
                    if (this.pick.mode === 'add') {
                        this.groupInfo = await this.post(this.urls.groupAdd.replace('__ID__', this.group), { user_ids: ids });
                        this.groupMembers = this.groupInfo.members.length;
                        this.view = 'groupInfo';
                    } else {
                        const g = await this.post(this.urls.groupCreate, { name: this.pick.name.trim(), members: ids });
                        this.openThread(0, g.name, false, g.group, g.members.length);
                    }
                } catch (e) { this.error = e.message; }
                this.pick.busy = false;
            },
            async openGroupInfo() {
                if (!this.group) return;
                this.view = 'groupInfo'; this.error = '';
                try {
                    this.groupInfo = await this.api(this.urls.groupShow.replace('__ID__', this.group));
                    this.groupMembers = this.groupInfo.members.length;
                    this.peerName = this.groupInfo.name;
                } catch (e) { this.error = e.message; }
            },
            backToThread() {
                this.openThread(0, this.groupInfo ? this.groupInfo.name : this.peerName, false, this.group, this.groupMembers);
            },
            async removeMember(u) {
                if (!confirm('Исключить ' + u.name + ' из группы?')) return;
                this.error = '';
                try {
                    this.groupInfo = await this.post(this.urls.groupRemove.replace('__ID__', this.group), { user_id: u.peer });
                    this.groupMembers = this.groupInfo.members.length;
                } catch (e) { this.error = e.message; }
            },
            async leaveGroup() {
                if (!confirm('Выйти из группы «' + (this.groupInfo ? this.groupInfo.name : this.peerName) + '»? Сообщения группы больше не будут видны.')) return;
                this.error = '';
                try {
                    await this.post(this.urls.groupRemove.replace('__ID__', this.group), { user_id: this.me });
                    this.group = 0; this.groupInfo = null;
                    this.backToList();
                } catch (e) { this.error = e.message; }
            },
            async renameGroup() {
                const name = prompt('Новое название группы', this.groupInfo ? this.groupInfo.name : '');
                if (name === null || !name.trim()) return;
                this.error = '';
                try {
                    this.groupInfo = await this.post(this.urls.groupRename.replace('__ID__', this.group), { name: name.trim() });
                    this.peerName = this.groupInfo.name;
                } catch (e) { this.error = e.message; }
            },
            async loadUsers() {
                try { this.users = (await this.api(this.withParams(this.urls.users, { q: this.search }))).users; } catch (e) {}
            },

            // ---- навигация ----
            toggle() { this.open ? this.close() : this.openPanel(); },
            async openPanel() {
                if (!(await this.ensurePanel())) return;
                this.open = true; this.view = 'list'; this.loadConversations();
            },

            /**
             * Разметка панели не приходит с каждой страницей — подгружается при первом открытии (GET /chat/panel)
             * и встаёт на место [data-chat-panel-host]. Alpine сам инициализирует вставленное внутри x-data виджета.
             * @returns {Promise<boolean>} панель на месте
             */
            ensurePanel() {
                if (rootEl && !rootEl.querySelector('[data-chat-panel-host]')) return Promise.resolve(true);
                this._panelLoading ??= (async () => {
                    try {
                        const res = await fetch(this.urls.panel, { credentials: 'same-origin', headers: { Accept: 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
                        if (!res.ok) throw new Error('Ошибка ' + res.status);
                        const html = await res.text();
                        const host = rootEl.querySelector('[data-chat-panel-host]');
                        if (host) host.outerHTML = html;
                        await new Promise(r => setTimeout(r, 0));   // дать Alpine подхватить вставленное
                        return true;
                    } catch (e) {
                        this._panelLoading = null;   // следующая попытка — заново
                        alert('Не удалось открыть чат. Проверьте связь и попробуйте ещё раз.');
                        return false;
                    }
                })();
                return this._panelLoading;
            },
            close() { this.open = false; this.actionsFor = null; this.refreshSummary(); },
            backToList() { this.view = 'list'; this.messages = []; this.replyTo = null; this.clearFiles(); this.loadConversations(); },
            startNew() {
                this.view = 'new'; this.search = ''; this.loadUsers();
                this.$nextTick(() => this.el("search") && this.el("search").focus());
            },
            async openThread(peer, name, online, group = 0, members = 0) {
                this.view = 'thread';
                this.peer = group ? 0 : peer; this.peerName = name; this.peerOnline = online;
                if (group !== this.group) this.groupInfo = null;
                this.group = group || 0; this.groupMembers = members || 0;
                if (this.group && !this.groupMembers) {
                    // открыли из уведомления — число участников ещё неизвестно
                    this.api(this.urls.groupShow.replace('__ID__', this.group))
                        .then(g => { if (this.group === g.group) { this.groupInfo = g; this.groupMembers = g.members.length; } })
                        .catch(() => {});
                }
                this.messages = []; this.hasMore = false; this.error = ''; this.draft = '';
                this.replyTo = null; this.clearFiles(); this.actionsFor = null; this.typingNames = [];
                this.loading = true;
                try {
                    // Открываем на первом непрочитанном (если есть)
                    const data = await this.api(this.withParams(this.urls.messages, this.chan({ initial: 1 })));
                    this.messages = data.messages; this.hasMore = data.has_more;
                    this.firstUnreadId = data.first_unread_id;
                    this.applyMeta(data);
                    this.scrollToUnread();
                    this.markRead();
                } catch (e) { this.error = e.message; }
                this.loading = false;
                this.$nextTick(() => this.el("input") && this.el("input").focus());
            },

            // ---- данные из ответа сервера ----
            applyMeta(data) {
                if (typeof data.peer_read_id === 'number') this.peerReadId = data.peer_read_id;
                if (Array.isArray(data.reads)) this.generalReads = data.reads;
                if (Array.isArray(data.typing)) this.typingNames = data.typing;
                if (data.server_time) this.since = data.server_time;
                if ('pinned' in data) this.pinned = data.pinned;
                for (const u of (data.updates || [])) {
                    const m = this.messages.find(x => x.id === u.id);
                    if (m) m.reactions = u.reactions;
                }
            },

            // ---- сообщения ----
            lastId() {
                for (let i = this.messages.length - 1; i >= 0; i--) {
                    if (typeof this.messages[i].id === 'number') return this.messages[i].id;
                }
                return 0;
            },
            async fetchNew() {
                if (this.loading) return;
                try {
                    const after = this.lastId();
                    // Пустой канал: «последнего id» ещё нет — забираем последнюю страницу целиком
                    const params = after ? this.chan({ after }) : this.chan();
                    if (after && this.since) params.since = this.since;
                    const data = await this.api(this.withParams(this.urls.messages, params));
                    this.applyMeta(data);
                    const known = new Set(this.messages.map(m => m.id));
                    const fresh = [];
                    for (const m of data.messages) {
                        if (known.has(m.id)) continue;
                        // своё сообщение пришло опросом раньше ответа на отправку — заменяем временное
                        const tmp = m.mine && this.messages.find(x => x.pending && x.body === m.body && !x.attachments.length);
                        if (tmp) { Object.assign(tmp, m, { pending: false, failed: false }); continue; }
                        fresh.push(m);
                    }
                    if (fresh.length) {
                        this.messages.push(...fresh);
                        if (this.stickToBottom) this.scrollBottom();
                        this.markRead();
                    }
                } catch (e) {}
            },
            async loadOlder() {
                const first = this.messages.find(m => typeof m.id === 'number');
                if (!first) return;
                this.loadingOlder = true;
                const list = this.el("list"), prevHeight = list ? list.scrollHeight : 0;
                try {
                    const data = await this.api(this.withParams(this.urls.messages, this.chan({ before: first.id })));
                    this.messages.unshift(...data.messages);
                    this.hasMore = data.has_more;
                    this.applyMeta(data);
                    this.$nextTick(() => { if (list) list.scrollTop = list.scrollHeight - prevHeight; });
                } catch (e) {}
                this.loadingOlder = false;
            },

            // ---- отправка ----
            async send() {
                const body = this.draft.trim();
                if (!body && !this.pendingFiles.length) return;
                this.error = '';
                const keys = this.pendingFiles.map(f => f.key);
                const tmp = {
                    id: 'tmp-' + (++this.tmpSeq), body, at: new Date().toISOString(),
                    mine: true, sender_id: this.me, sender_name: '', pending: true, failed: false,
                    reply_to: this.replyTo ? { ...this.replyTo } : null,
                    reactions: [],
                    attachments: this.pendingFiles.map(f => ({
                        id: 'tmp-' + f.key, kind: f.preview ? 'image' : 'file', name: f.name, size: f.size,
                        url: '', thumb_url: f.preview,
                    })),
                    fileKeys: keys,
                };
                this.messages.push(tmp);
                // Важно: менять нужно реактивную обёртку Alpine из массива, а не исходный объект
                const live = this.messages[this.messages.length - 1];
                this.draft = ''; this.replyTo = null; this.pendingFiles = [];
                this.$nextTick(() => { if (this.el("input")) { this.el("input").style.height = ''; this.el("input").focus(); } });
                this.stickToBottom = true; this.scrollBottom();
                await this.deliver(live);
            },
            async deliver(tmp) {
                const peer = this.peer, group = this.group;
                const form = new FormData();
                for (const [k, v] of Object.entries(this.chan())) form.append(k, v);
                if (tmp.body) form.append('body', tmp.body);
                if (tmp.reply_to) form.append('reply_to_id', tmp.reply_to.id);
                for (const key of (tmp.fileKeys || [])) {
                    const f = fileStore.get(key);
                    if (f) form.append('files[]', f, f.name);
                }
                try {
                    const data = await this.post(this.urls.send, form);
                    for (const key of (tmp.fileKeys || [])) fileStore.delete(key);
                    if (peer !== this.peer || group !== this.group) return;   // уже в другой переписке
                    const real = this.messages.find(x => x.id === data.message.id);
                    if (real && real !== tmp) this.messages.splice(this.messages.indexOf(tmp), 1);
                    else Object.assign(tmp, data.message, { pending: false, failed: false, fileKeys: [] });
                } catch (e) {
                    tmp.pending = false; tmp.failed = true;
                    this.error = e.status === 429 ? 'Слишком часто — подождите немного.'
                               : e.status === 413 ? 'Файл слишком большой для сервера.'
                               : e.status ? e.message
                               : 'Нет связи с сервером. Проверьте сеть и повторите.';
                    if (this.stickToBottom) this.scrollBottom();
                }
            },
            retry(m) {
                m.failed = false; m.pending = true; this.error = '';
                this.deliver(m);
            },
            markRead() {
                const id = this.lastId();
                if (id) this.post(this.urls.read, this.chan({ last_id: id })).catch(() => {});
            },
            notifyTyping() {
                const now = Date.now();
                if (now - lastTypingSent < 3000 || !this.draft.trim()) return;
                lastTypingSent = now;
                this.post(this.urls.typing, this.chan()).catch(() => {});
            },
            typingText() {
                const n = this.typingNames;
                if (!n.length) return '';
                if (this.peer !== 0) return 'печатает…';
                return n.length === 1 ? n[0] + ' печатает…' : n.length + ' человека печатают…';
            },

            // ---- вложения ----
            addFiles(list) {
                for (const file of Array.from(list || [])) {
                    if (this.pendingFiles.length >= 5) { this.error = 'Не больше 5 файлов в одном сообщении.'; break; }
                    if (file.size > 20 * 1024 * 1024) { this.error = '«' + file.name + '» больше 20 МБ.'; continue; }
                    const key = ++fileSeq;
                    fileStore.set(key, file);
                    this.pendingFiles.push({
                        key, name: file.name || 'вставка.png', size: file.size,
                        preview: /^image\//.test(file.type) ? URL.createObjectURL(file) : null,
                    });
                }
                this.$nextTick(() => this.el("input") && this.el("input").focus());
            },
            removeFile(key) {
                const f = this.pendingFiles.find(x => x.key === key);
                if (f && f.preview) URL.revokeObjectURL(f.preview);
                fileStore.delete(key);
                this.pendingFiles = this.pendingFiles.filter(x => x.key !== key);
            },
            clearFiles() {
                for (const f of this.pendingFiles) this.removeFile(f.key);
                this.pendingFiles = [];
            },
            // Чистый текст: без смайлов (они — реакциями), «залго», рисунков из символов и невидимых знаков.
            // Те же наборы, что в App\Rules\NoEmoji и App\Rules\CleanText
            stripEmoji(t) {
                const rules = [
                    [/[\u{1F000}-\u{1FAFF}\u{2600}-\u{27BF}\u{2B00}-\u{2BFF}\u{2300}-\u{23FF}\u{3030}\u{303D}\u{3297}\u{3299}\u{FE00}-\u{FE0F}\u{200D}\u{20E3}\u{E0020}-\u{E007F}]/gu, '', 'Смайлы в сообщениях не разрешены — поставьте реакцию на сообщение'],
                    [/(\p{M})\p{M}+/gu, '$1', '«Наслоенные» символы (залго) не разрешены'],
                    [/[\u{2800}-\u{28FF}\u{2500}-\u{25FF}]/gu, '', 'Рисунки из символов не разрешены'],
                    [/[\u{200B}-\u{200F}\u{202A}-\u{202E}\u{2060}-\u{206F}\u{FEFF}\u{00AD}\u{180E}]/gu, '', 'Невидимые символы убраны'],
                ];
                let value = t.value, caret = t.selectionStart, hint = '';
                for (const [re, to, text] of rules) {
                    if (!value.match(re)) continue;
                    caret = value.slice(0, caret).replace(re, to).length;
                    value = value.replace(re, to);
                    hint = hint || text;
                }
                if (!hint) return;
                this.draft = t.value = value;
                t.setSelectionRange(caret, caret);
                this.error = hint;
                clearTimeout(this._emojiT);
                this._emojiT = setTimeout(() => { if (this.error === hint) this.error = ''; }, 3500);
            },
            onPaste(e) {
                const files = Array.from(e.clipboardData && e.clipboardData.files || []);
                if (files.length) { e.preventDefault(); this.addFiles(files); }
            },
            images(m) { return (m.attachments || []).filter(a => a.kind === 'image'); },
            files(m) { return (m.attachments || []).filter(a => a.kind !== 'image'); },
            fileExt(name) { const m = /\.([a-z0-9]{1,4})$/i.exec(name || ''); return m ? m[1].toUpperCase() : 'FILE'; },
            fileSize(b) { return !b ? '' : b < 1024 ? b + ' Б' : b < 1048576 ? Math.round(b / 1024) + ' КБ' : (b / 1048576).toFixed(1) + ' МБ'; },

            // ---- реакции и ответы ----
            toggleActions(m, e) {
                if (m.pending || m.failed || e.target.closest('a,button')) return;
                // выделяют текст для копирования — меню реакций не открываем
                const sel = window.getSelection && window.getSelection();
                if (sel && !sel.isCollapsed && e.currentTarget.contains(sel.anchorNode)) return;
                this.actionsFor = this.actionsFor === m.id ? null : m.id;
            },
            isBump(m, emoji) { return !!this.bump && this.bump.id === m.id && this.bump.emoji === emoji; },
            quickReact(m, e) {
                if (m.pending || m.failed || e.target.closest('a,button')) return;
                window.getSelection && window.getSelection().removeAllRanges();
                this.react(m, this.reactionSet[0]);
            },
            async react(m, emoji) {
                this.actionsFor = null;
                if (typeof m.id !== 'number') return;
                // анимация «пружинки» + вылетающий эмодзи
                this.bump = null;
                this.$nextTick(() => {
                    this.bump = { id: m.id, emoji };
                    clearTimeout(this._bumpT);
                    this._bumpT = setTimeout(() => { this.bump = null; }, 750);
                });
                // мгновенно на экране, затем сверяем с сервером
                // одна реакция на сообщение: прежняя своя снимается
                const list = (m.reactions || []).map(r => ({ ...r, names: [...r.names] }));
                const prev = list.find(x => x.mine);
                if (prev) { prev.count--; prev.mine = false; }
                if (!prev || prev.emoji !== emoji) {
                    const r = list.find(x => x.emoji === emoji);
                    if (r) { r.count++; r.mine = true; } else list.push({ emoji, count: 1, mine: true, names: ['Вы'] });
                }
                m.reactions = list.filter(x => x.count > 0);
                try {
                    const data = await this.post(this.urls.react.replace('__ID__', m.id), { emoji });
                    m.reactions = data.reactions;
                } catch (e) { this.error = e.message; }
            },
            startReply(m) {
                this.actionsFor = null;
                const imgs = this.images(m), files = this.files(m);
                this.replyTo = {
                    id: m.id, sender_name: m.mine ? 'себе' : m.sender_name,
                    body: m.body ? m.body.slice(0, 90) : '',
                    media: imgs.length ? { label: imgs.length > 1 ? 'Фото (' + imgs.length + ')' : 'Фото', thumb_url: imgs[0].thumb_url }
                         : (files.length ? { label: 'Файл: ' + files[0].name, thumb_url: null } : null),
                };
                this.$nextTick(() => this.el("input") && this.el("input").focus());
            },
            scrollToMessage(id) {
                const el = id && document.getElementById('chat-msg-' + id);
                if (!el) { this.error = 'Это сообщение выше — нажмите «Показать ранние сообщения».'; return; }
                el.scrollIntoView({ block: 'center', behavior: 'smooth' });
                this.flashId = id;
                setTimeout(() => { if (this.flashId === id) this.flashId = null; }, 1600);
            },

            // ---- отметки «отправлено / прочитано» ----
            readCount(id) {
                const r = this.generalReads; let lo = 0, hi = r.length;
                while (lo < hi) { const mid = (lo + hi) >> 1; if (r[mid] >= id) hi = mid; else lo = mid + 1; }
                return r.length - lo;
            },
            status(m) {
                if (m.failed) return 'failed';
                if (m.pending) return 'pending';
                const read = this.peer === 0 ? this.readCount(m.id) > 0 : this.peerReadId >= m.id;
                return read ? 'read' : 'sent';
            },
            statusTitle(m) {
                switch (this.status(m)) {
                    case 'failed':  return 'Не отправлено';
                    case 'pending': return 'Отправляется…';
                    case 'read':    return this.peer === 0 ? 'Прочитали: ' + this.readCount(m.id) : 'Прочитано';
                    default:        return this.peer === 0 ? 'Отправлено, пока никто не прочитал' : 'Отправлено, не прочитано';
                }
            },

            // ---- прокрутка и ввод ----
            /**
             * Открыть переписку на первом непрочитанном (разделитель «Новые сообщения»)
             * или внизу. Сообщения отрисовываются не мгновенно — ждём их, пробуя несколько раз.
             */
            scrollToUnread(attempt = 0, lastTop = null) {
                const list = this.el("list");
                if (this.view !== 'thread') return;
                const target = this.firstUnreadId && document.getElementById('chat-msg-' + this.firstUnreadId);
                // Пользователь сам прокрутил — больше не вмешиваемся
                if (lastTop !== null && list && Math.abs(list.scrollTop - lastTop) > 2) return;

                if (list && (target || !this.firstUnreadId)) {
                    if (target) {
                        // позиция относительно самого списка (offsetTop считался бы от всей панели)
                        list.scrollTop += target.getBoundingClientRect().top - list.getBoundingClientRect().top - 8;
                    } else {
                        list.scrollTop = list.scrollHeight;
                    }
                    this.onScroll();
                    if (!target) this.stickToBottom = true;
                }
                // Сообщения и миниатюры дорисовываются не сразу — поправляем позицию ~1 с
                if (attempt < 12) {
                    const top = list ? list.scrollTop : null;
                    setTimeout(() => this.scrollToUnread(attempt + 1, top), 80);
                }
            },
            onScroll() {
                const el = this.el("list"); if (!el) return;
                this.stickToBottom = el.scrollHeight - el.scrollTop - el.clientHeight < 60;
            },
            scrollBottom() { this.$nextTick(() => { const el = this.el("list"); if (el) el.scrollTop = el.scrollHeight; }); },
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
        };
    });
});
