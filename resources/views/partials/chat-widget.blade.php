@auth
{{-- Чат: общий канал + личные сообщения. Ответы, вложения, реакции, «печатает…».
     Сервер — App\Http\Controllers\ChatController. История хранится 7 дней. --}}
<div x-data="chatWidget({
        me: {{ auth()->id() }},
        reactions: @js(\App\Models\ChatMessage::REACTIONS),
        canPin: @js(auth()->user()->canManageContent()),
        mentionName: @js((string) \Illuminate\Support\Str::of(auth()->user()->employee?->short_name ?: auth()->user()->name)->trim()->before(' ')),
        urls: {
            summary: @js(route('chat.summary')),
            conversations: @js(route('chat.conversations')),
            users: @js(route('chat.users')),
            messages: @js(route('chat.messages')),
            send: @js(route('chat.send')),
            read: @js(route('chat.read')),
            typing: @js(route('chat.typing')),
            react: @js(route('chat.react', ['message' => '__ID__'])),
            pin: @js(route('chat.pin', ['message' => '__ID__'])),
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
                    <button type="button" class="ui-iconbtn" @click="toggleNotifications()" x-show="notifySupported"
                            :title="notifyOn ? 'Уведомления включены — нажмите, чтобы выключить' : 'Включить уведомления о личных сообщениях и упоминаниях'"
                            :aria-label="notifyOn ? 'Выключить уведомления' : 'Включить уведомления'" :style="notifyOn ? 'color: var(--ui-accent)' : ''">
                        <svg x-show="notifyOn" class="w-5 h-5" fill="currentColor" viewBox="0 0 24 24"><path d="M12 22a2.5 2.5 0 002.45-2h-4.9A2.5 2.5 0 0012 22zm7-6V11a7 7 0 00-5.5-6.84V3.5a1.5 1.5 0 00-3 0v.66A7 7 0 005 11v5l-2 2v1h18v-1l-2-2z"/></svg>
                        <svg x-show="!notifyOn" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/><path stroke-linecap="round" stroke-width="2" d="M4 4l16 16"/></svg>
                    </button>
                    <button type="button" class="ui-iconbtn" @click="startNew()" title="Новое сообщение" aria-label="Новое сообщение">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                    </button>
                    <button type="button" class="ui-iconbtn" @click="close()" aria-label="Закрыть чат">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </header>

                <div class="ui-chat__scroll">
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
                                <span class="ui-chat__conv-last">
                                    <template x-if="conv.general.last">
                                        <span class="ui-chat__conv-preview">
                                            <span x-text="conv.general.last.author + ':'"></span>
                                            <img x-show="conv.general.last.media && conv.general.last.media.thumb_url" :src="conv.general.last.media && conv.general.last.media.thumb_url" class="ui-chat__mini-thumb" alt="" loading="lazy">
                                            <span x-show="conv.general.last.media && !conv.general.last.body" class="ui-chat__media-label" x-text="conv.general.last.media && conv.general.last.media.label"></span>
                                            <span x-show="conv.general.last.body" x-text="conv.general.last.body"></span>
                                        </span>
                                    </template>
                                    <span x-show="!conv.general.last" class="ui-chat__media-label">Сообщение всем сотрудникам</span>
                                </span>
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
                                    <span class="ui-chat__conv-last">
                                        <span x-show="c.last.mine" class="ui-chat__tick" :class="c.last.read ? 'ui-chat__tick--read' : 'ui-chat__tick--sent'"
                                              :title="c.last.read ? 'Прочитано' : 'Отправлено, не прочитано'">
                                            <svg x-show="!c.last.read" class="ui-chat__tick-ico" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3.5 8.5l3 3 6-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                            <svg x-show="c.last.read" class="ui-chat__tick-ico ui-chat__tick-ico--double" viewBox="0 0 20 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1.5 8.5l3 3 6-7M8.5 11.5l1 0 6-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                        </span>
                                        <span x-show="c.last.mine">Вы:</span>
                                        <img x-show="c.last.media && c.last.media.thumb_url" :src="c.last.media && c.last.media.thumb_url" class="ui-chat__mini-thumb" alt="" loading="lazy">
                                        <span x-show="c.last.media && !c.last.body" class="ui-chat__media-label" x-text="c.last.media && c.last.media.label"></span>
                                        <span x-show="c.last.body" x-text="c.last.body"></span>
                                    </span>
                                    <span x-show="c.unread" class="ui-chat__count" x-text="c.unread"></span>
                                </span>
                            </span>
                        </button>
                    </template>

                    <div x-show="!conv.direct.length" class="ui-chat__empty">
                        Личных переписок пока нет.
                        <button type="button" class="ui-chat__link" @click="startNew()">Написать сотруднику</button>
                    </div>
                    <p class="ui-chat__retention">История чата хранится 7 дней</p>
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
                        <input type="text" x-model="search" @input.debounce.300ms="loadUsers()" x-ref="search" placeholder="Фамилия или имя…" autocomplete="off">
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
            <div class="ui-chat__screen" @dragover.prevent="dragOver = true" @dragleave.self="dragOver = false" @drop.prevent="dragOver = false; addFiles($event.dataTransfer.files)">
                <header class="ui-chat__head">
                    <button type="button" class="ui-iconbtn" @click="backToList()" aria-label="Назад к списку">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <div class="flex-1 min-w-0">
                        <div class="ui-chat__title truncate" x-text="peerName"></div>
                        <div class="ui-chat__sub">
                            <span x-show="typingNames.length" class="ui-chat__typing">
                                <span class="ui-chat__typing-dots" aria-hidden="true"><i></i><i></i><i></i></span>
                                <span x-text="typingText()"></span>
                            </span>
                            <span x-show="!typingNames.length" x-text="peer === 0 ? 'все сотрудники' : (peerOnline ? 'в сети' : 'не в сети')"></span>
                        </div>
                    </div>
                    <button type="button" class="ui-iconbtn" @click="close()" aria-label="Закрыть чат">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </header>

                <div x-show="peer === 0 && pinned" class="ui-chat__pinned" role="note">
                    <svg class="w-4 h-4 shrink-0" style="color: var(--ui-accent)" fill="currentColor" viewBox="0 0 24 24"><path d="M16 3a1 1 0 01.7 1.7L15 6.4V10l2.7 2.7a1 1 0 01-.7 1.7H13v6a1 1 0 01-2 0v-6H6.3a1 1 0 01-.7-1.7L8.3 10V6.4L6.6 4.7A1 1 0 017.3 3H16z"/></svg>
                    <button type="button" class="ui-chat__pinned-body" @click="pinned && scrollToMessage(pinned.id)" title="Перейти к сообщению">
                        <span class="ui-chat__pinned-title">Закреплено · <span x-text="pinned && pinned.sender_name"></span></span>
                        <span class="ui-chat__pinned-text" x-text="pinned && (pinned.body || 'Вложение')"></span>
                    </button>
                    <button type="button" x-show="canPin" class="ui-iconbtn" style="width: 28px; height: 28px" @click="togglePin(pinned)" title="Открепить" aria-label="Открепить">✕</button>
                </div>

                <div class="ui-chat__scroll ui-chat__messages ui-nocopy" x-ref="list" @scroll.passive="onScroll()" @click.self="actionsFor = null"
                     @copy.prevent @cut.prevent @contextmenu.prevent @dragstart.prevent>
                    <div class="text-center py-2" x-show="hasMore">
                        <button type="button" class="ui-chat__link" @click="loadOlder()" :disabled="loadingOlder"
                                x-text="loadingOlder ? 'Загрузка…' : 'Показать ранние сообщения'"></button>
                    </div>

                    <template x-for="(m, i) in messages" :key="m.id">
                        <div :id="'chat-msg-' + m.id">
                            <div x-show="showDay(i)" class="ui-chat__day"><span x-text="dayLabel(m.at)"></span></div>
                            <div x-show="m.id === firstUnreadId" class="ui-chat__new-divider"><span>Новые сообщения</span></div>

                            <div class="ui-chat__msg" :class="{ 'ui-chat__msg--mine': m.mine, 'ui-chat__msg--flash': flashId === m.id }">
                                <div class="ui-chat__bubble-wrap">
                                    {{-- Панель действий: реакции + ответить --}}
                                    <div x-show="actionsFor === m.id && !m.pending && !m.failed" x-cloak class="ui-chat__actions" @click.stop>
                                        <template x-for="(e, ei) in reactionSet" :key="e">
                                            <button type="button" class="ui-chat__actions-emoji" :style="'--i:' + ei" @click="react(m, e)" x-text="e" :aria-label="'Реакция ' + e"></button>
                                        </template>
                                        <button type="button" x-show="canPin && peer === 0" class="ui-chat__actions-reply" @click="togglePin(m)"
                                                :title="pinned && pinned.id === m.id ? 'Открепить' : 'Закрепить для всех'">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 3l5 5-4 1-4 4 1 5-3 3-4-4-4 4M8 13l-5-5 3-3 5 1 4-4"/></svg>
                                        </button>
                                        <button type="button" class="ui-chat__actions-reply" @click="startReply(m)" title="Ответить">
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                                        </button>
                                    </div>

                                    <div class="ui-chat__bubble" @click="toggleActions(m, $event)" @dblclick.prevent="quickReact(m, $event)">
                                        <div x-show="!m.mine && peer === 0 && showAuthor(i)" class="ui-chat__author" x-text="m.sender_name"></div>

                                        {{-- Цитата --}}
                                        <button type="button" x-show="m.reply_to" class="ui-chat__quote" @click.stop="scrollToMessage(m.reply_to && m.reply_to.id)">
                                            <img x-show="m.reply_to && m.reply_to.media && m.reply_to.media.thumb_url" :src="m.reply_to && m.reply_to.media && m.reply_to.media.thumb_url" class="ui-chat__quote-thumb" alt="" loading="lazy">
                                            <span class="min-w-0">
                                                <span class="ui-chat__quote-author" x-text="m.reply_to && m.reply_to.sender_name"></span>
                                                <span class="ui-chat__quote-text">
                                                    <span x-show="m.reply_to && m.reply_to.body" x-text="m.reply_to && m.reply_to.body"></span>
                                                    <span x-show="m.reply_to && !m.reply_to.body && m.reply_to.media" class="ui-chat__media-label" x-text="m.reply_to && m.reply_to.media && m.reply_to.media.label"></span>
                                                </span>
                                            </span>
                                        </button>

                                        {{-- Картинки --}}
                                        <div x-show="images(m).length" class="ui-chat__images" :class="'ui-chat__images--' + Math.min(images(m).length, 3)">
                                            <template x-for="a in images(m)" :key="a.id">
                                                <button type="button" class="ui-chat__image" @click.stop="lightbox = a">
                                                    <img :src="a.thumb_url" :alt="a.name" loading="lazy">
                                                </button>
                                            </template>
                                        </div>

                                        {{-- Файлы --}}
                                        <template x-for="a in files(m)" :key="a.id">
                                            <a :href="a.url" class="ui-chat__file" @click.stop :download="a.name" :class="a.url ? '' : 'pointer-events-none'">
                                                <span class="ui-chat__file-ico" x-text="fileExt(a.name)"></span>
                                                <span class="min-w-0">
                                                    <span class="ui-chat__file-name" x-text="a.name"></span>
                                                    <span class="ui-chat__file-size" x-text="fileSize(a.size)"></span>
                                                </span>
                                            </a>
                                        </template>

                                        <div x-show="m.body" class="ui-chat__text" x-html="formatBody(m.body)"></div>

                                        <div class="ui-chat__meta">
                                            <span x-text="timeOnly(m.at)"></span>
                                            <span x-show="m.mine" class="ui-chat__tick" :class="'ui-chat__tick--' + status(m)" :title="statusTitle(m)" :aria-label="statusTitle(m)">
                                                <svg x-show="status(m) === 'pending'" class="ui-chat__tick-ico" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.6"><circle cx="8" cy="8" r="6"/><path d="M8 5v3l2 1.5" stroke-linecap="round"/></svg>
                                                <svg x-show="status(m) === 'sent'" class="ui-chat__tick-ico" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3.5 8.5l3 3 6-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                <svg x-show="status(m) === 'read'" class="ui-chat__tick-ico ui-chat__tick-ico--double" viewBox="0 0 20 16" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M1.5 8.5l3 3 6-7M8.5 11.5l1 0 6-7" stroke-linecap="round" stroke-linejoin="round"/></svg>
                                                <svg x-show="status(m) === 'failed'" class="ui-chat__tick-ico" viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="8" cy="8" r="6.2"/><path d="M8 4.8v3.6M8 10.9v.1" stroke-linecap="round"/></svg>
                                            </span>
                                        </div>
                                    </div>

                                    {{-- Реакции --}}
                                    <div x-show="m.reactions && m.reactions.length" class="ui-chat__reactions">
                                        <template x-for="r in (m.reactions || [])" :key="r.emoji">
                                            <button type="button" class="ui-chat__reaction" :class="{ 'ui-chat__reaction--mine': r.mine, 'ui-chat__reaction--bump': isBump(m, r.emoji) }"
                                                    @click.stop="react(m, r.emoji)" :title="r.names.join(', ')">
                                                <span class="ui-chat__reaction-emoji" x-text="r.emoji"></span>
                                                <span class="ui-chat__reaction-count" x-text="r.count"></span>
                                                <span x-show="isBump(m, r.emoji) && r.mine" class="ui-chat__reaction-burst" x-text="r.emoji" aria-hidden="true"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </div>
                            <div x-show="m.failed" class="ui-chat__retry">
                                <button type="button" @click="retry(m)">Не отправлено — нажмите, чтобы повторить</button>
                            </div>
                        </div>
                    </template>

                    <div x-show="!messages.length && !loading" class="ui-chat__empty">
                        <span x-text="peer === 0 ? 'В общем чате пока тихо. Напишите первым!' : 'Начните переписку — сообщение увидит только собеседник.'"></span>
                    </div>
                </div>

                {{-- Ответ и выбранные файлы --}}
                <div x-show="replyTo" class="ui-chat__compose-bar">
                    <img x-show="replyTo && replyTo.media && replyTo.media.thumb_url" :src="replyTo && replyTo.media && replyTo.media.thumb_url" class="ui-chat__quote-thumb" alt="">
                    <svg class="w-4 h-4 flex-shrink-0" style="color: var(--ui-accent)" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/></svg>
                    <div class="flex-1 min-w-0">
                        <div class="ui-chat__quote-author" x-text="replyTo && ('Ответ ' + replyTo.sender_name)"></div>
                        <div class="ui-chat__quote-text">
                            <span x-show="replyTo && replyTo.body" x-text="replyTo && replyTo.body"></span>
                            <span x-show="replyTo && !replyTo.body && replyTo.media" class="ui-chat__media-label" x-text="replyTo && replyTo.media && replyTo.media.label"></span>
                        </div>
                    </div>
                    <button type="button" class="ui-iconbtn" style="width: 28px; height: 28px" @click="replyTo = null" aria-label="Отменить ответ">✕</button>
                </div>
                <div x-show="pendingFiles.length" class="ui-chat__pending">
                    <template x-for="f in pendingFiles" :key="f.key">
                        <div class="ui-chat__pending-item" :title="f.name">
                            <img x-show="f.preview" :src="f.preview" alt="" @click="lightbox = { url: f.preview, name: f.name }" style="cursor: zoom-in">
                            <span x-show="!f.preview" class="ui-chat__file-ico" x-text="fileExt(f.name)"></span>
                            <button type="button" @click="removeFile(f.key)" aria-label="Убрать файл">✕</button>
                        </div>
                    </template>
                </div>

                <div x-show="mentionList.length" class="ui-chat__mentions" role="listbox" aria-label="Упомянуть сотрудника">
                    <template x-for="(u, ui) in mentionList" :key="u.peer">
                        <button type="button" class="ui-chat__mention-item" :class="ui === mentionIdx && 'is-active'" role="option"
                                @mousedown.prevent="pickMention(u)">
                            <span class="ui-chat__avatar" style="width: 26px; height: 26px; font-size: .62rem" x-text="u.initials"></span>
                            <span x-text="u.name"></span>
                            <span x-show="u.online" class="ui-chat__online-dot"></span>
                        </button>
                    </template>
                </div>

                <form class="ui-chat__compose" @submit.prevent="send()">
                    <button type="button" class="ui-chat__attach" @click="$refs.fileInput.click()" title="Прикрепить файл или фото" aria-label="Прикрепить файл">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                    </button>
                    <input type="file" x-ref="fileInput" multiple class="hidden"
                           accept="image/*,.pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.odt,.ods,.rtf,.txt,.csv,.zip,.7z,.rar,.bin,.hex,.log"
                           @change="addFiles($event.target.files); $event.target.value = ''">
                    <textarea x-ref="input" x-model="draft" rows="1" maxlength="2000"
                              placeholder="Сообщение…" aria-label="Текст сообщения"
                              @keydown.enter="if (mentionList.length) { $event.preventDefault(); pickMention(mentionList[mentionIdx]); return; } if (!$event.shiftKey) { $event.preventDefault(); send(); }"
                              @keydown.tab="if (mentionList.length) { $event.preventDefault(); pickMention(mentionList[mentionIdx]); }"
                              @keydown.arrow-down="if (mentionList.length) { $event.preventDefault(); mentionIdx = (mentionIdx + 1) % mentionList.length; }"
                              @keydown.arrow-up="if (mentionList.length) { $event.preventDefault(); mentionIdx = (mentionIdx + mentionList.length - 1) % mentionList.length; }"
                              @keydown.escape.stop="mentionList = []"
                              @blur="setTimeout(() => mentionList = [], 150)"
                              @input="stripEmoji($event.target); autoGrow($event.target); notifyTyping(); onMentionInput($event.target)"
                              @paste="onPaste($event)"></textarea>
                    <button type="submit" class="ui-chat__send" :disabled="!draft.trim() && !pendingFiles.length" aria-label="Отправить">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8"/></svg>
                    </button>
                </form>
                <div x-show="error" x-text="error" class="ui-chat__error" role="alert"></div>

                <div x-show="dragOver" class="ui-chat__drop">Отпустите, чтобы прикрепить</div>
            </div>
        </template>
    </section>

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

<script>
document.addEventListener('alpine:init', () => {
    Alpine.data('chatWidget', (cfg) => {
        // Файлы держим ВНЕ реактивного состояния: прокси Alpine нельзя положить в FormData
        const fileStore = new Map();
        let fileSeq = 0, lastTypingSent = 0, rootEl = null;

        return {
            me: cfg.me, urls: cfg.urls, reactionSet: cfg.reactions, canPin: cfg.canPin, mentionName: cfg.mentionName,
            mentions: 0, pinned: null, mentionList: [], mentionIdx: 0, mentionTimer: null,
            notifyOn: false, notifyAfter: null, notifySupported: 'Notification' in window && window.isSecureContext, hiddenTicks: 0,
            open: false, view: 'list',
            unread: 0,
            conv: { general: { unread: 0, online: 0, last: null }, direct: [] },
            users: [], search: '',
            peer: 0, peerName: '', peerOnline: false,
            messages: [], hasMore: false, loading: false, loadingOlder: false,
            draft: '', error: '',
            peerReadId: 0, generalReads: [], tmpSeq: 0,
            firstUnreadId: null, since: null, typingNames: [],
            replyTo: null, pendingFiles: [], actionsFor: null, flashId: null, bump: null,
            lightbox: null, dragOver: false,
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
                if (!document.hidden && this.open && this.view === 'thread' && this.peer === n.peer) return;
                if (!this.notifyOn || Notification.permission !== 'granted') return;
                try {
                    const note = new Notification(n.mention ? n.name + ' упомянул(а) вас в общем чате' : 'Сообщение от ' + n.name, {
                        body: n.preview, icon: '/icons/icon-192.png', tag: 'uti-chat-' + n.id,
                    });
                    note.onclick = () => {
                        window.focus();
                        this.open = true;
                        this.openThread(n.peer, n.peer === 0 ? 'Общий чат' : n.name, false);
                        note.close();
                    };
                } catch (e) {}
            },

            // ---- @упоминания ----
            formatBody(text) {
                const esc = String(text).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
                const me = (this.mentionName || '').toLowerCase();
                return esc.replace(/(^|[\s(,.;:!?])@([\p{L}\-]{2,40})/gu, (all, pre, name) => {
                    const mine = name.toLowerCase() === me || name.toLowerCase() === 'все';
                    return pre + '<span class="ui-chat__mention' + (mine ? ' ui-chat__mention--me' : '') + '">@' + name + '</span>';
                });
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
                        if (this.peer === 0 && 'все'.startsWith(q.toLowerCase())) list.unshift({ peer: -1, name: 'все', initials: '@', online: false });
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
                    this.unread = (this.conv.general.unread || 0) + this.conv.direct.reduce((s, c) => s + (c.unread || 0), 0);
                } catch (e) {}
            },
            async loadUsers() {
                try { this.users = (await this.api(this.withParams(this.urls.users, { q: this.search }))).users; } catch (e) {}
            },

            // ---- навигация ----
            toggle() { this.open ? this.close() : this.openPanel(); },
            openPanel() { this.open = true; this.view = 'list'; this.loadConversations(); },
            close() { this.open = false; this.actionsFor = null; this.refreshSummary(); },
            backToList() { this.view = 'list'; this.messages = []; this.replyTo = null; this.clearFiles(); this.loadConversations(); },
            startNew() {
                this.view = 'new'; this.search = ''; this.loadUsers();
                this.$nextTick(() => this.el("search") && this.el("search").focus());
            },
            async openThread(peer, name, online) {
                this.view = 'thread';
                this.peer = peer; this.peerName = name; this.peerOnline = online;
                this.messages = []; this.hasMore = false; this.error = ''; this.draft = '';
                this.replyTo = null; this.clearFiles(); this.actionsFor = null; this.typingNames = [];
                this.loading = true;
                try {
                    // Открываем на первом непрочитанном (если есть)
                    const data = await this.api(this.withParams(this.urls.messages, { peer, initial: 1 }));
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
                    const params = after ? { peer: this.peer, after } : { peer: this.peer };
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
                    const data = await this.api(this.withParams(this.urls.messages, { peer: this.peer, before: first.id }));
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
                const peer = this.peer;
                const form = new FormData();
                form.append('peer', peer);
                if (tmp.body) form.append('body', tmp.body);
                if (tmp.reply_to) form.append('reply_to_id', tmp.reply_to.id);
                for (const key of (tmp.fileKeys || [])) {
                    const f = fileStore.get(key);
                    if (f) form.append('files[]', f, f.name);
                }
                try {
                    const data = await this.post(this.urls.send, form);
                    for (const key of (tmp.fileKeys || [])) fileStore.delete(key);
                    if (peer !== this.peer) return;                       // уже в другой переписке
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
                if (id) this.post(this.urls.read, { peer: this.peer, last_id: id }).catch(() => {});
            },
            notifyTyping() {
                const now = Date.now();
                if (now - lastTypingSent < 3000 || !this.draft.trim()) return;
                lastTypingSent = now;
                this.post(this.urls.typing, { peer: this.peer }).catch(() => {});
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
</script>
@endauth
