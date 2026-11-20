{{-- Панель чата: отдаётся отдельно (GET /chat/panel) и подгружается при первом открытии — partials/chat-widget.
     Вставляется внутрь x-data="chatWidget" — Alpine подхватывает её сам, данные и методы общие. --}}
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
                <button type="button" class="ui-iconbtn" @click="startPick('create')" title="Новая группа" aria-label="Новая группа">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z"/></svg>
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

                <div class="ui-chat__section" x-show="conv.groups && conv.groups.length">Группы</div>
                <template x-for="g in (conv.groups || [])" :key="'g' + g.group">
                    <button type="button" class="ui-chat__conv" @click="openThread(0, g.name, false, g.group, g.members)">
                        <span class="ui-chat__avatar ui-chat__avatar--group"><span x-text="g.initials"></span></span>
                        <span class="ui-chat__conv-body">
                            <span class="ui-chat__conv-top">
                                <span class="ui-chat__conv-name" x-text="g.name"></span>
                                <span class="ui-chat__conv-time" x-text="g.last ? timeShort(g.last.at) : ''"></span>
                            </span>
                            <span class="ui-chat__conv-bottom">
                                <span class="ui-chat__conv-last">
                                    <template x-if="g.last">
                                        <span class="ui-chat__conv-preview">
                                            <span x-text="g.last.author + ':'"></span>
                                            <img x-show="g.last.media && g.last.media.thumb_url" :src="g.last.media && g.last.media.thumb_url" class="ui-chat__mini-thumb" alt="" loading="lazy">
                                            <span x-show="g.last.media && !g.last.body" class="ui-chat__media-label" x-text="g.last.media && g.last.media.label"></span>
                                            <span x-show="g.last.body" x-text="g.last.body"></span>
                                        </span>
                                    </template>
                                    <span x-show="!g.last" class="ui-chat__media-label" x-text="'Участников: ' + g.members"></span>
                                </span>
                                <span x-show="g.unread" class="ui-chat__count" x-text="g.unread"></span>
                            </span>
                        </span>
                    </button>
                </template>

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
                <div x-show="!(conv.groups && conv.groups.length)" class="ui-chat__empty" style="padding: .25rem 1.25rem .5rem">
                    <button type="button" class="ui-chat__link" @click="startPick('create')">Создать группу из нескольких человек</button>
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

    {{-- ===== Выбор нескольких участников: новая группа / добавить в группу ===== --}}
    <template x-if="view === 'pick'">
        <div class="ui-chat__screen">
            <header class="ui-chat__head">
                <button type="button" class="ui-iconbtn" @click="pick.mode === 'add' ? openGroupInfo() : backToList()" aria-label="Назад">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <div class="flex-1 min-w-0">
                    <div class="ui-chat__title truncate" x-text="pick.mode === 'add' ? 'Добавить участников' : 'Новая группа'"></div>
                    <div class="ui-chat__sub" x-text="pick.selected.length ? 'выбрано: ' + pick.selected.length : 'отметьте одного или нескольких'"></div>
                </div>
            </header>
            <div class="px-3 pb-2 flex flex-col gap-2">
                <input x-show="pick.mode === 'create'" type="text" x-model="pick.name" maxlength="80" class="ui-input w-full"
                       placeholder="Название группы, например «Смена А»" aria-label="Название группы" x-ref="groupName">
                <label class="ui-search">
                    <span class="sr-only">Найти сотрудника</span>
                    <svg fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    <input type="text" x-model="search" @input.debounce.300ms="loadUsers()" x-ref="search" placeholder="Фамилия или имя…" autocomplete="off">
                </label>
                <div x-show="pick.selected.length" class="flex flex-wrap gap-1">
                    <template x-for="u in pick.selected" :key="'s' + u.peer">
                        <button type="button" class="ui-btn ui-btn--ghost ui-btn--sm" @click="togglePick(u)" :title="'Убрать ' + u.name">
                            <span x-text="u.name"></span> ✕
                        </button>
                    </template>
                </div>
            </div>
            <div class="ui-chat__scroll">
                <template x-for="u in pickable()" :key="'p' + u.peer">
                    <button type="button" class="ui-chat__conv" @click="togglePick(u)" :aria-pressed="isPicked(u)">
                        <span class="ui-chat__avatar">
                            <span x-text="u.initials"></span>
                            <span x-show="u.online" class="ui-chat__online-dot"></span>
                        </span>
                        <span class="ui-chat__conv-body">
                            <span class="ui-chat__conv-name" x-text="u.name"></span>
                            <span class="ui-chat__conv-last" x-text="u.online ? 'в сети' : 'не в сети'"></span>
                        </span>
                        <input type="checkbox" class="rounded" :checked="isPicked(u)" tabindex="-1" aria-hidden="true" style="pointer-events: none">
                    </button>
                </template>
                <div x-show="!pickable().length" class="ui-chat__empty">Никого не найдено</div>
            </div>
            <div x-show="error" x-text="error" class="ui-chat__error" role="alert"></div>
            <div class="p-3 border-t" style="border-color: var(--ui-border)">
                <button type="button" class="ui-btn ui-btn--primary w-full" @click="submitPick()"
                        :disabled="pick.busy || !pick.selected.length || (pick.mode === 'create' && !pick.name.trim())"
                        x-text="pick.busy ? 'Сохранение…' : (pick.mode === 'add' ? 'Добавить (' + pick.selected.length + ')' : 'Создать группу (' + (pick.selected.length + 1) + ' уч.)')"></button>
            </div>
        </div>
    </template>

    {{-- ===== О группе: участники ===== --}}
    <template x-if="view === 'groupInfo'">
        <div class="ui-chat__screen">
            <header class="ui-chat__head">
                <button type="button" class="ui-iconbtn" @click="backToThread()" aria-label="Назад к переписке">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </button>
                <div class="flex-1 min-w-0">
                    <div class="ui-chat__title truncate" x-text="groupInfo ? groupInfo.name : peerName"></div>
                    <div class="ui-chat__sub" x-text="groupInfo ? 'участников: ' + groupInfo.members.length : 'загрузка…'"></div>
                </div>
                <button type="button" class="ui-iconbtn" x-show="groupInfo && groupInfo.can_manage" @click="renameGroup()" title="Переименовать" aria-label="Переименовать группу">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                </button>
            </header>
            <div class="px-3 pb-2">
                <button type="button" class="ui-btn ui-btn--primary w-full" @click="startPick('add')"
                        :disabled="!groupInfo || groupInfo.members.length >= groupInfo.max">+ Добавить участников</button>
            </div>
            <div class="ui-chat__scroll">
                <template x-for="u in (groupInfo ? groupInfo.members : [])" :key="'m' + u.peer">
                    <div class="ui-chat__conv" style="cursor: default">
                        <span class="ui-chat__avatar">
                            <span x-text="u.initials"></span>
                            <span x-show="u.online" class="ui-chat__online-dot"></span>
                        </span>
                        <span class="ui-chat__conv-body">
                            <span class="ui-chat__conv-name" x-text="u.name + (u.me ? ' (вы)' : '')"></span>
                            <span class="ui-chat__conv-last" x-text="u.owner ? 'создатель группы' : (u.online ? 'в сети' : 'не в сети')"></span>
                        </span>
                        <button type="button" x-show="groupInfo.can_manage && !u.me" class="ui-iconbtn" style="width: 28px; height: 28px"
                                @click="removeMember(u)" :title="'Исключить ' + u.name" :aria-label="'Исключить ' + u.name">✕</button>
                    </div>
                </template>
            </div>
            <div x-show="error" x-text="error" class="ui-chat__error" role="alert"></div>
            <div class="p-3 border-t" style="border-color: var(--ui-border)">
                <button type="button" class="ui-btn ui-btn--ghost w-full" style="color: var(--ui-bad)" @click="leaveGroup()">Выйти из группы</button>
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
                        <span x-show="!typingNames.length" x-text="group ? 'участников: ' + groupMembers : (peer === 0 ? 'все сотрудники' : (peerOnline ? 'в сети' : 'не в сети'))"></span>
                    </div>
                </div>
                <button type="button" class="ui-iconbtn" x-show="group" @click="openGroupInfo()" title="Участники группы" aria-label="Участники группы">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0zm6 3a2 2 0 11-4 0 2 2 0 014 0zM7 10a2 2 0 11-4 0 2 2 0 014 0z"/></svg>
                </button>
                <button type="button" class="ui-iconbtn" x-show="games && peer !== 0" @click="inviteBattle()" :disabled="inviting"
                        title="Пригласить в морской бой" aria-label="Пригласить в морской бой">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" viewBox="0 0 24 24"><path d="M3 17l2 3h14l2-3M5 17V9l7-4 7 4v8M12 5v12"/></svg>
                </button>
                <button type="button" class="ui-iconbtn" @click="close()" aria-label="Закрыть чат">
                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </header>

            <div x-show="peer === 0 && !group && pinned" class="ui-chat__pinned" role="note">
                <svg class="w-4 h-4 shrink-0" style="color: var(--ui-accent)" fill="currentColor" viewBox="0 0 24 24"><path d="M16 3a1 1 0 01.7 1.7L15 6.4V10l2.7 2.7a1 1 0 01-.7 1.7H13v6a1 1 0 01-2 0v-6H6.3a1 1 0 01-.7-1.7L8.3 10V6.4L6.6 4.7A1 1 0 017.3 3H16z"/></svg>
                <button type="button" class="ui-chat__pinned-body" @click="pinned && scrollToMessage(pinned.id)" title="Перейти к сообщению">
                    <span class="ui-chat__pinned-title">Закреплено · <span x-text="pinned && pinned.sender_name"></span></span>
                    <span class="ui-chat__pinned-text" x-text="pinned && (pinned.body || 'Вложение')"></span>
                </button>
                <button type="button" x-show="canPin" class="ui-iconbtn" style="width: 28px; height: 28px" @click="togglePin(pinned)" title="Открепить" aria-label="Открепить">✕</button>
            </div>

            <div class="ui-chat__scroll ui-chat__messages" x-ref="list" @scroll.passive="onScroll()" @click.self="actionsFor = null">
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
                                    <button type="button" x-show="canPin && peer === 0 && !group" class="ui-chat__actions-reply" @click="togglePin(m)"
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
                    <span x-text="group ? 'В группе пока тихо. Сообщение увидят только участники группы.' : (peer === 0 ? 'В общем чате пока тихо. Напишите первым!' : 'Начните переписку — сообщение увидит только собеседник.')"></span>
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
