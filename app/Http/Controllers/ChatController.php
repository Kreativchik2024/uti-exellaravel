<?php

namespace App\Http\Controllers;

use App\Models\ActiveWorkerSession;
use App\Models\ChatAttachment;
use App\Models\ChatGroup;
use App\Models\ChatMessage;
use App\Models\ChatRead;
use App\Models\ChatReaction;
use App\Models\User;
use App\Rules\CleanText;
use App\Rules\NoEmoji;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Intervention\Image\Encoders\WebpEncoder;
use Intervention\Image\Laravel\Facades\Image;
use Symfony\Component\HttpFoundation\Response;

/**
 * Чат: общий канал (peer = 0) и личные переписки (peer = id собеседника).
 * Ответы, вложения (картинки и файлы), реакции, «печатает…».
 * История — 7 дней (ChatMessage::RETENTION_DAYS, очистка: chat:prune).
 * Доставка — опросом с клиента (resources/views/partials/chat-widget.blade.php).
 */
class ChatController extends Controller
{
    private const PAGE = 50;
    private const UNREAD_CONTEXT = 15;     // сколько прочитанных показать над первым непрочитанным
    private const UNREAD_MAX = 300;
    private const ONLINE_SECONDS = 180;
    private const TYPING_SECONDS = 6;
    private const MAX_FILES = 5;
    private const MAX_FILE_KB = 20480;     // 20 МБ
    private const IMAGE_MAX_SIDE = 1920;
    private const THUMB_MAX_SIDE = 480;

    /** Разрешённые расширения вложений (исполняемое и HTML/SVG — нельзя). */
    private const FILE_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'webp', 'gif',
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'odt', 'ods', 'rtf', 'txt', 'csv',
        'zip', '7z', 'rar', 'bin', 'hex', 'log',
    ];
    private const RESIZABLE_IMAGES = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];

    // ---------------------------------------------------------------- helpers

    /** Имя для чата: короткое ФИО сотрудника, иначе имя учётки. */
    private function displayNames(iterable $userIds): array
    {
        $ids = collect($userIds)->filter()->unique()->values();
        if ($ids->isEmpty()) return [];

        return User::query()
            ->whereIn('users.id', $ids)
            ->leftJoin('employees', 'employees.user_id', '=', 'users.id')
            ->get(['users.id', 'users.name', 'employees.short_name'])
            ->mapWithKeys(fn ($u) => [$u->id => CleanText::strip(NoEmoji::strip((string) ($u->short_name ?: $u->name))) ?: 'Без имени'])
            ->all();
    }

    private function onlineUserIds(): array
    {
        return ActiveWorkerSession::query()
            ->where('last_heartbeat_at', '>=', now()->subSeconds(self::ONLINE_SECONDS))
            ->whereNotNull('user_id')
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    private function initials(string $name): string
    {
        $parts = preg_split('/[\s.]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY) ?: ['?'];
        return mb_strtoupper(mb_substr($parts[0], 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));
    }

    /** Проверить собеседника: активный пользователь и не сам себе. */
    private function resolvePeer(Request $r): int
    {
        $peer = (int) $r->input('peer', 0);
        if ($peer === 0) return 0;

        abort_if($peer === (int) $r->user()->id, 422, 'Нельзя писать самому себе.');
        abort_unless(User::whereKey($peer)->where('is_active', true)->exists(), 404, 'Собеседник не найден.');

        return $peer;
    }

    /** Группа из запроса (group=ID): только для её участников. 0 — не группа. */
    private function resolveGroup(Request $r): int
    {
        $group = (int) $r->input('group', 0);
        if ($group <= 0) return 0;

        $g = ChatGroup::find($group);
        abort_unless($g && $g->hasMember((int) $r->user()->id), 404, 'Группа не найдена.');

        return $group;
    }

    /**
     * Канал из запроса: [peer, group]. group > 0 — группа (peer = 0),
     * иначе peer = 0 — общий канал, peer > 0 — личная переписка.
     */
    private function resolveChannel(Request $r): array
    {
        $group = $this->resolveGroup($r);
        return [$group ? 0 : $this->resolvePeer($r), $group];
    }

    /** Сообщения канала за последние 7 дней. */
    private function channelQuery(int $me, int $peer, int $group = 0)
    {
        return ($group > 0
            ? ChatMessage::query()->inGroup($group)
            : ($peer === 0
                ? ChatMessage::query()->general()
                : ChatMessage::query()->between($me, $peer))
        )->recent();
    }

    /** Ключ канала для «печатает…». */
    private function channelKey(int $me, int $peer, int $group = 0): string
    {
        if ($group > 0) return 'grp' . $group;
        return $peer === 0 ? 'g' : 'd' . min($me, $peer) . '-' . max($me, $peer);
    }

    /** Мои группы: id => модель. */
    private function myGroups(int $me): EloquentCollection
    {
        return ChatGroup::query()->withMember($me)->get()->keyBy('id');
    }

    /** Короткое описание сообщения для списка и цитат. */
    private function preview(?ChatMessage $m): string
    {
        if (!$m) return '';
        if (filled($m->body)) return mb_strimwidth(CleanText::strip($m->body), 0, 90, '…');

        return $this->media($m)['label'] ?? '';
    }

    /**
     * Вложение для превью (цитата ответа, список разговоров): подпись и миниатюра фото.
     * @return array{label: string, thumb_url: ?string}|null
     */
    private function media(ChatMessage $m): ?array
    {
        $atts = $m->relationLoaded('attachments') ? $m->attachments : $m->attachments()->get();
        $first = $atts->first();
        if (! $first) return null;
        $images = $atts->filter(fn ($a) => $a->isImage());

        return [
            'label'     => $first->isImage() ? ($images->count() > 1 ? 'Фото (' . $images->count() . ')' : 'Фото') : 'Файл: ' . $first->original_name,
            'thumb_url' => $images->isNotEmpty() ? $this->attachmentUrl($images->first(), true) : null,
        ];
    }

    /** Превью сообщения: текст + вложение отдельно (подпись вложения — не текст сообщения). */
    private function previewParts(ChatMessage $m): array
    {
        return [
            'body'  => filled($m->body) ? mb_strimwidth(CleanText::strip($m->body), 0, 90, '…') : '',
            'media' => $this->media($m),
        ];
    }

    private function attachmentUrl(ChatAttachment $a, bool $thumb = false): string
    {
        return route('chat.attachment', array_filter([$a->id, $thumb ? 'thumb' : null]));
    }

    /** Сводка реакций сообщения в порядке набора: эмодзи, сколько, моя ли, кто. */
    private function reactionSummary(Collection $reactions, int $me, array $names): array
    {
        return $reactions->groupBy('emoji')
            ->sortBy(fn ($group, $emoji) => array_search($emoji, ChatMessage::REACTIONS, true))
            ->map(fn ($group, $emoji) => [
                'emoji' => $emoji,
                'count' => $group->count(),
                'mine'  => $group->contains('user_id', $me),
                'names' => $group->map(fn ($r) => $names[$r->user_id] ?? '—')->values()->all(),
            ])->values()->all();
    }

    private function present(Collection $messages, int $me): array
    {
        // collect([...]) — обычная коллекция; для дозагрузки связей нужна коллекция моделей
        $messages = new EloquentCollection($messages->all());
        $messages->loadMissing(['attachments', 'reactions', 'replyTo.attachments']);

        $names = $this->displayNames($messages->pluck('sender_id')
            ->merge($messages->pluck('replyTo.sender_id'))
            ->merge($messages->pluck('reactions')->flatten()->pluck('user_id')));

        return $messages->map(fn (ChatMessage $m) => [
            'id'          => $m->id,
            'body'        => $m->body === null ? null : CleanText::strip($m->body),
            'at'          => $m->created_at?->toIso8601String(),
            'sender_id'   => $m->sender_id,
            'sender_name' => $names[$m->sender_id] ?? 'Удалён',
            'mine'        => $m->sender_id === $me,
            'reply_to'    => $m->replyTo ? [
                'id'          => $m->replyTo->id,
                'sender_name' => $names[$m->replyTo->sender_id] ?? 'Удалён',
                'preview'     => $this->preview($m->replyTo),
            ] + $this->previewParts($m->replyTo) : null,
            'attachments' => $m->attachments->map(fn (ChatAttachment $a) => [
                'id'        => $a->id,
                'kind'      => $a->kind,
                'name'      => $a->original_name,
                'size'      => $a->size,
                'width'     => $a->width,
                'height'    => $a->height,
                'url'       => $this->attachmentUrl($a),
                'thumb_url' => $a->isImage() ? $this->attachmentUrl($a, true) : null,
            ])->values()->all(),
            'reactions'   => $this->reactionSummary($m->reactions, $me, $names),
        ])->values()->all();
    }

    /**
     * Докуда прочитано другими — для галочек ✓ / ✓✓ у своих сообщений.
     * Личный: id последнего прочитанного собеседником. Общий: отметки всех
     * остальных (по возрастанию) — клиент считает «прочитали: N».
     */
    private function receipts(int $me, int $peer, int $group = 0): array
    {
        if ($group === 0 && $peer !== 0) {
            return ['peer_read_id' => (int) ChatRead::where('user_id', $peer)->where('peer_id', $me)->where('group_id', 0)->value('last_read_id')];
        }

        return ['reads' => ChatRead::query()
            ->where('peer_id', 0)
            ->where('group_id', $group)
            ->where('user_id', '!=', $me)
            ->where('last_read_id', '>', 0)
            ->orderBy('last_read_id')
            ->pluck('last_read_id')
            ->map(fn ($id) => (int) $id)
            ->all()];
    }

    /** Кто печатает в канале (кроме меня). */
    private function typingNames(int $me, int $peer, int $group = 0): array
    {
        $now = now()->getTimestamp();
        $list = Cache::get('chat.typing.' . $this->channelKey($me, $peer, $group), []);

        return collect($list)
            ->filter(fn ($row, $uid) => (int) $uid !== $me && $row['until'] >= $now)
            ->pluck('name')->values()->all();
    }

    /** Докуда я прочитал общий канал. */
    private function generalReadId(int $me): int
    {
        return (int) ChatRead::where('user_id', $me)->where('peer_id', 0)->where('group_id', 0)->value('last_read_id');
    }

    /** Непрочитанные: общий канал + личные по собеседникам + группы (за последние 7 дней). */
    private function unread(int $me): array
    {
        $generalRead = $this->generalReadId($me);

        $general = ChatMessage::query()->general()->recent()
            ->where('id', '>', $generalRead)
            ->where('sender_id', '!=', $me)
            ->count();

        $groups = DB::table('chat_messages as m')
            ->join('chat_group_members as gm', fn ($j) => $j->on('gm.group_id', '=', 'm.group_id')->where('gm.user_id', '=', $me))
            ->leftJoin('chat_reads as r', fn ($j) => $j->on('r.group_id', '=', 'm.group_id')->where('r.user_id', '=', $me)->where('r.peer_id', '=', 0))
            ->where('m.sender_id', '!=', $me)
            ->where('m.created_at', '>=', now()->subDays(ChatMessage::RETENTION_DAYS))
            ->whereRaw('m.id > COALESCE(r.last_read_id, 0)')
            ->groupBy('m.group_id')
            ->selectRaw('m.group_id as gid, COUNT(*) as c')
            ->pluck('c', 'gid')
            ->map(fn ($c) => (int) $c)
            ->all();

        $direct = DB::table('chat_messages as m')
            ->leftJoin('chat_reads as r', fn ($j) => $j->on('r.peer_id', '=', 'm.sender_id')->where('r.user_id', '=', $me)->where('r.group_id', '=', 0))
            ->where('m.recipient_id', $me)
            ->where('m.created_at', '>=', now()->subDays(ChatMessage::RETENTION_DAYS))
            ->whereRaw('m.id > COALESCE(r.last_read_id, 0)')
            ->groupBy('m.sender_id')
            ->selectRaw('m.sender_id as sid, COUNT(*) as c')
            ->pluck('c', 'sid')
            ->map(fn ($c) => (int) $c)
            ->all();

        return ['general' => $general, 'direct' => $direct, 'groups' => $groups];
    }

    // ---------------------------------------------------------------- actions

    /** Лёгкий опрос для значка на кнопке. */
    public function summary(Request $r): JsonResponse
    {
        $me = (int) $r->user()->id;
        $u = $this->unread($me);
        $after = max(0, (int) $r->query('after', 0));

        // Упоминания меня в общем чате (непрочитанные)
        $generalRead = $this->generalReadId($me);
        $mentions = fn () => ChatMessage::query()->general()->recent()
            ->where('sender_id', '!=', $me)->where('id', '>', $generalRead)
            ->where(fn ($w) => $this->whereMentions($w, $me));

        // Новое для уведомления: личные сообщения мне, сообщения моих групп и упоминания — после $after
        $notify = collect();
        if ($after > 0) {
            $reads = ChatRead::where('user_id', $me)->where('group_id', 0)->pluck('last_read_id', 'peer_id');
            $direct = ChatMessage::query()->recent()->with('attachments')
                ->where('recipient_id', $me)->where('id', '>', $after)
                ->orderBy('id')->limit(10)->get()
                ->filter(fn ($m) => $m->id > (int) ($reads[$m->sender_id] ?? 0));
            $mentioned = $mentions()->with('attachments')->where('id', '>', $after)->orderBy('id')->limit(10)->get();

            $groups = $u['groups'] ? $this->myGroups($me) : new EloquentCollection();
            $groupReads = ChatRead::where('user_id', $me)->where('peer_id', 0)->where('group_id', '>', 0)->pluck('last_read_id', 'group_id');
            $inGroups = $groups->isEmpty() ? collect() : ChatMessage::query()->recent()->with('attachments')
                ->whereIn('group_id', $groups->keys())->where('sender_id', '!=', $me)->where('id', '>', $after)
                ->orderBy('id')->limit(10)->get()
                ->filter(fn ($m) => $m->id > (int) ($groupReads[$m->group_id] ?? 0));

            $names = $this->displayNames($direct->pluck('sender_id')->merge($mentioned->pluck('sender_id'))->merge($inGroups->pluck('sender_id')));

            $notify = $direct->map(fn ($m) => ['id' => $m->id, 'peer' => $m->sender_id, 'group' => 0, 'mention' => false, 'name' => $names[$m->sender_id] ?? '—', 'preview' => $this->preview($m)])
                ->merge($mentioned->map(fn ($m) => ['id' => $m->id, 'peer' => 0, 'group' => 0, 'mention' => true, 'name' => $names[$m->sender_id] ?? '—', 'preview' => $this->preview($m)]))
                ->merge($inGroups->map(fn ($m) => ['id' => $m->id, 'peer' => 0, 'group' => $m->group_id, 'group_name' => $groups[$m->group_id]->name ?? 'Группа', 'mention' => false, 'name' => $names[$m->sender_id] ?? '—', 'preview' => $this->preview($m)]))
                ->sortBy('id')->take(-5)->values();
        }

        return response()->json([
            'unread'   => $u['general'] + array_sum($u['direct']) + array_sum($u['groups']),
            'mentions' => $mentions()->count(),
            'notify'   => $notify,
            'last_id'  => (int) ChatMessage::max('id'),
        ])->header('Cache-Control', 'no-store');
    }

    /** Фамилия для упоминаний: первое слово отображаемого имени («Иванов О.П.» → «Иванов»). */
    private function mentionName(int $userId): string
    {
        return (string) Str::of($this->displayNames([$userId])[$userId] ?? '')->trim()->before(' ');
    }

    /** Условие «сообщение упоминает пользователя»: @Фамилия или @все. */
    private function whereMentions($query, int $me): void
    {
        $name = $this->mentionName($me);
        $query->where('body', 'like', '%@все%');
        if ($name !== '') {
            $query->orWhere('body', 'like', '%@' . addcslashes($name, '%_\\') . '%');
        }
    }

    /** Закрепить сообщение общего чата (одно на канал) или открепить. Мастер и админ. */
    public function pin(Request $r, ChatMessage $message): JsonResponse
    {
        abort_unless($r->user()->canManageContent(), 403);
        abort_unless($message->recipient_id === null && $message->group_id === null, 422, 'Закрепить можно только сообщение общего чата');

        $pin = $message->pinned_at === null;
        DB::transaction(function () use ($message, $pin, $r) {
            ChatMessage::whereNotNull('pinned_at')->update(['pinned_at' => null, 'pinned_by' => null]);
            if ($pin) {
                $message->forceFill(['pinned_at' => now(), 'pinned_by' => $r->user()->id])->saveQuietly();
            }
        });
        \App\Models\AuditLog::write('chat', $pin ? 'pinned' : 'unpinned', ($pin ? 'Закрепил' : 'Открепил') . ' сообщение: ' . $this->preview($message), ['subject_type' => 'chat', 'subject_id' => $message->id, 'section_id' => null]);

        return response()->json(['pinned' => $this->pinned((int) $r->user()->id)]);
    }

    /** Закреплённое сообщение общего чата (или null). */
    private function pinned(int $me): ?array
    {
        $m = ChatMessage::query()->general()->whereNotNull('pinned_at')->latest('pinned_at')->first();

        return $m ? $this->present(collect([$m]), $me)[0] + ['pinned_at' => $m->pinned_at->toIso8601String()] : null;
    }

    /** Список разговоров: общий канал + личные (последние сверху). */
    public function conversations(Request $r): JsonResponse
    {
        $me = (int) $r->user()->id;
        $unread = $this->unread($me);
        $online = array_flip($this->onlineUserIds());

        $lastGeneral = ChatMessage::query()->general()->recent()->with('attachments')->latest('id')->first();

        // Последние сообщения личных переписок — берём свежие и группируем по собеседнику
        $recent = ChatMessage::query()->recent()
            ->where(fn ($w) => $w->where('recipient_id', $me)->orWhere(fn ($x) => $x->where('sender_id', $me)->whereNotNull('recipient_id')))
            ->with('attachments')
            ->latest('id')
            ->limit(1000)
            ->get();

        $byPeer = [];
        foreach ($recent as $m) {
            $peer = $m->sender_id === $me ? $m->recipient_id : $m->sender_id;
            $byPeer[$peer] ??= $m;
        }
        $names = $this->displayNames(array_keys($byPeer) + [-1 => $lastGeneral?->sender_id]);

        // Докуда собеседники прочитали мои сообщения
        $peerReads = ChatRead::query()
            ->whereIn('user_id', array_keys($byPeer))
            ->where('peer_id', $me)
            ->pluck('last_read_id', 'user_id')
            ->all();

        $direct = [];
        foreach ($byPeer as $peer => $m) {
            $name = $names[$peer] ?? 'Удалён';
            $direct[] = [
                'peer'     => $peer,
                'name'     => $name,
                'initials' => $this->initials($name),
                'online'   => isset($online[$peer]),
                'unread'   => $unread['direct'][$peer] ?? 0,
                'last'     => $this->previewParts($m) + [
                    'at'   => $m->created_at?->toIso8601String(),
                    'mine' => $m->sender_id === $me,
                    'read' => $m->sender_id === $me && (int) ($peerReads[$peer] ?? 0) >= $m->id,
                ],
            ];
        }

        return response()->json([
            'general' => [
                'unread' => $unread['general'],
                'online' => count($online),
                'last'   => $lastGeneral ? $this->previewParts($lastGeneral) + [
                    'at'     => $lastGeneral->created_at?->toIso8601String(),
                    'author' => $lastGeneral->sender_id === $me ? 'Вы' : ($names[$lastGeneral->sender_id] ?? ''),
                ] : null,
            ],
            'groups' => $this->groupList($me, $unread['groups']),
            'direct' => $direct,
        ])->header('Cache-Control', 'no-store');
    }

    /** Мои группы для списка разговоров: свежие сверху. */
    private function groupList(int $me, array $unread): array
    {
        $groups = $this->myGroups($me);
        if ($groups->isEmpty()) return [];

        $counts = DB::table('chat_group_members')->whereIn('group_id', $groups->keys())
            ->groupBy('group_id')->selectRaw('group_id, COUNT(*) as c')->pluck('c', 'group_id');

        // Последнее сообщение каждой группы — одним запросом
        $lastIds = ChatMessage::query()->recent()->whereIn('group_id', $groups->keys())
            ->groupBy('group_id')->selectRaw('MAX(id) as id')->pluck('id');
        $last = ChatMessage::query()->with('attachments')->whereIn('id', $lastIds)->get()->keyBy('group_id');
        $names = $this->displayNames($last->pluck('sender_id'));

        return $groups->map(function (ChatGroup $g) use ($me, $unread, $counts, $last, $names) {
            $m = $last[$g->id] ?? null;
            return [
                'group'    => $g->id,
                'name'     => $g->name,
                'initials' => $this->initials($g->name),
                'members'  => (int) ($counts[$g->id] ?? 0),
                'unread'   => $unread[$g->id] ?? 0,
                'last'     => $m ? $this->previewParts($m) + [
                    'at'     => $m->created_at?->toIso8601String(),
                    'author' => $m->sender_id === $me ? 'Вы' : ($names[$m->sender_id] ?? ''),
                ] : null,
                'sort'     => $m?->created_at?->getTimestamp() ?? $g->created_at?->getTimestamp() ?? 0,
            ];
        })->sortByDesc('sort')->values()->all();
    }

    // ---------------------------------------------------------------- группы

    /** Участники и права для экрана «О группе». */
    private function groupInfo(ChatGroup $g, Request $r): array
    {
        $me = (int) $r->user()->id;
        $ids = $g->memberIds();
        $names = $this->displayNames($ids);
        $online = array_flip($this->onlineUserIds());

        $members = collect($ids)->map(fn ($id) => [
            'peer'     => $id,
            'name'     => $names[$id] ?? 'Удалён',
            'initials' => $this->initials($names[$id] ?? '?'),
            'online'   => isset($online[$id]),
            'owner'    => (int) $g->created_by === $id,
            'me'       => $id === $me,
        ])->sortBy(fn ($u) => [$u['owner'] ? 0 : 1, $u['name']])->values()->all();

        return [
            'group'      => $g->id,
            'name'       => $g->name,
            'can_manage' => $g->canManage($r->user()),
            'max'        => ChatGroup::MAX_MEMBERS,
            'members'    => $members,
        ];
    }

    private function findMyGroup(Request $r, ChatGroup $group): ChatGroup
    {
        abort_unless($group->hasMember((int) $r->user()->id), 404, 'Группа не найдена.');
        return $group;
    }

    /** Проверка списка добавляемых: только активные пользователи, без меня. */
    private function validMemberIds(Request $r, string $key): array
    {
        $ids = collect($r->validate([
            $key       => ['required', 'array', 'min:1', 'max:' . ChatGroup::MAX_MEMBERS],
            $key . '.*' => ['integer'],
        ], [
            $key . '.required' => 'Выберите хотя бы одного участника.',
            $key . '.min'      => 'Выберите хотя бы одного участника.',
            $key . '.max'      => 'Не больше ' . ChatGroup::MAX_MEMBERS . ' участников.',
        ])[$key])->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === (int) $r->user()->id);

        return User::whereIn('id', $ids)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    private function groupName(Request $r): string
    {
        $name = $r->validate([
            'name' => ['required', 'string', 'max:80', new NoEmoji(), new CleanText()],
        ], [
            'name.required' => 'Введите название группы.',
            'name.max'      => 'Название — до 80 символов.',
        ])['name'];
        $name = trim(preg_replace('/\s+/u', ' ', $name));
        abort_if($name === '', 422, 'Введите название группы.');

        return $name;
    }

    /** Создать группу: название + сразу несколько участников. */
    public function createGroup(Request $r): JsonResponse
    {
        $name = $this->groupName($r);
        $ids = $this->validMemberIds($r, 'members');
        abort_if(empty($ids), 422, 'Выберите хотя бы одного участника.');
        $me = (int) $r->user()->id;

        $group = DB::transaction(function () use ($name, $ids, $me) {
            $g = ChatGroup::create(['name' => $name, 'created_by' => $me]);
            $g->addMembers(array_merge([$me], $ids), $me);
            return $g;
        });

        \App\Models\AuditLog::write('chat', 'group_created', 'Создал группу «' . $name . '» (' . (count($ids) + 1) . ' уч.)', ['subject_type' => 'chat_group', 'subject_id' => $group->id, 'section_id' => null]);

        return response()->json($this->groupInfo($group, $r), 201);
    }

    public function showGroup(Request $r, ChatGroup $group): JsonResponse
    {
        return response()->json($this->groupInfo($this->findMyGroup($r, $group), $r));
    }

    /** Добавить в группу сразу нескольких участников (может любой участник). */
    public function addGroupMembers(Request $r, ChatGroup $group): JsonResponse
    {
        $group = $this->findMyGroup($r, $group);
        $ids = $this->validMemberIds($r, 'user_ids');

        $added = DB::transaction(function () use ($group, $ids, $r) {
            ChatGroup::whereKey($group->id)->lockForUpdate()->first();
            $free = ChatGroup::MAX_MEMBERS - count($group->memberIds());
            $toAdd = array_values(array_diff($ids, $group->memberIds()));
            abort_if(count($toAdd) > $free, 422, 'В группе может быть не больше ' . ChatGroup::MAX_MEMBERS . ' участников.');
            return $group->addMembers($toAdd, (int) $r->user()->id);
        });

        return response()->json($this->groupInfo($group, $r) + ['added' => $added]);
    }

    /** Исключить участника (создатель / мастер / админ) или выйти из группы самому. */
    public function removeGroupMember(Request $r, ChatGroup $group): JsonResponse
    {
        $group = $this->findMyGroup($r, $group);
        $me = (int) $r->user()->id;
        $userId = (int) $r->validate(['user_id' => ['required', 'integer']])['user_id'];

        abort_unless($userId === $me || $group->canManage($r->user()), 403, 'Исключать участников может только создатель группы.');

        DB::transaction(function () use ($group, $userId) {
            $group->members()->detach($userId);
            ChatRead::where('user_id', $userId)->where('group_id', $group->id)->delete();

            $left = $group->memberIds();
            if (!$left) {
                $group->delete();                                         // никого не осталось — группа не нужна
            } elseif ((int) $group->created_by === $userId) {
                $group->forceFill(['created_by' => $left[0]])->save();    // создатель ушёл — права первому из оставшихся
            }
        });

        if ($userId === $me) {
            return response()->json(['left' => true]);
        }

        return response()->json($this->groupInfo($group->fresh(), $r));
    }

    /** Переименовать группу (создатель / мастер / админ). */
    public function renameGroup(Request $r, ChatGroup $group): JsonResponse
    {
        $group = $this->findMyGroup($r, $group);
        abort_unless($group->canManage($r->user()), 403, 'Переименовать группу может только её создатель.');

        $group->update(['name' => $this->groupName($r)]);

        return response()->json($this->groupInfo($group, $r));
    }

    /** Поиск сотрудника, чтобы начать личную переписку. */
    public function users(Request $r): JsonResponse
    {
        $me = (int) $r->user()->id;
        $q = trim((string) $r->query('q', ''));
        $online = array_flip($this->onlineUserIds());

        $rows = User::query()
            ->leftJoin('employees', 'employees.user_id', '=', 'users.id')
            ->where('users.is_active', true)
            ->where('users.id', '!=', $me)
            ->when($q !== '', fn ($w) => $w->where(fn ($x) => $x
                ->where('users.name', 'like', "%{$q}%")
                ->orWhere('employees.short_name', 'like', "%{$q}%")))
            ->orderBy('users.name')
            ->limit(200)
            ->get(['users.id', 'users.name', 'employees.short_name']);

        $list = $rows->map(function ($u) use ($online) {
            $name = CleanText::strip(NoEmoji::strip((string) ($u->short_name ?: $u->name))) ?: 'Без имени';
            return ['peer' => $u->id, 'name' => $name, 'initials' => $this->initials($name), 'online' => isset($online[$u->id])];
        })
            ->sortBy(fn ($u) => [$u['online'] ? 0 : 1, $u['name']])   // сначала те, кто онлайн
            ->take(30)->values();

        return response()->json(['users' => $list]);
    }

    /**
     * Сообщения канала:
     *  - initial=1 — при открытии: окно вокруг первого непрочитанного (first_unread_id);
     *  - after=ID  — новые сообщения + изменившиеся реакции (since — время прошлого опроса);
     *  - before=ID — более ранние (прокрутка вверх).
     */
    public function messages(Request $r): JsonResponse
    {
        $me = (int) $r->user()->id;
        [$peer, $group] = $this->resolveChannel($r);
        $after = (int) $r->query('after', 0);
        $before = (int) $r->query('before', 0);
        $firstUnreadId = null;
        $updates = [];

        if ($after > 0) {
            $rows = $this->channelQuery($me, $peer, $group)->where('id', '>', $after)->orderBy('id')->limit(200)->get();
            $hasMore = null;

            // Реакции на уже показанные сообщения, изменившиеся с прошлого опроса
            if ($since = $r->query('since')) {
                $changed = $this->channelQuery($me, $peer, $group)
                    ->where('id', '<=', $after)
                    ->where('updated_at', '>', Carbon::parse($since))
                    ->orderBy('id')->limit(200)->get();
                $updates = collect($this->present($changed, $me))
                    ->map(fn ($m) => ['id' => $m['id'], 'reactions' => $m['reactions']])->all();
            }
        } elseif ($r->boolean('initial') && $before === 0) {
            $lastRead = (int) ChatRead::where('user_id', $me)->where('peer_id', $peer)->where('group_id', $group)->value('last_read_id');
            $firstUnreadId = $this->channelQuery($me, $peer, $group)
                ->where('id', '>', $lastRead)->where('sender_id', '!=', $me)->min('id');

            if ($firstUnreadId) {
                $context = $this->channelQuery($me, $peer, $group)->where('id', '<', $firstUnreadId)
                    ->orderByDesc('id')->limit(self::UNREAD_CONTEXT)->get()->reverse();
                $unread = $this->channelQuery($me, $peer, $group)->where('id', '>=', $firstUnreadId)
                    ->orderBy('id')->limit(self::UNREAD_MAX)->get();
                $rows = $context->concat($unread)->values();
                $oldest = $rows->first()?->id;
                $hasMore = $oldest && $this->channelQuery($me, $peer, $group)->where('id', '<', $oldest)->exists();
            }
        }

        if (!isset($rows)) {
            $q = $this->channelQuery($me, $peer, $group);
            if ($before > 0) $q->where('id', '<', $before);
            $rows = $q->orderByDesc('id')->limit(self::PAGE + 1)->get();
            $hasMore = $rows->count() > self::PAGE;
            $rows = $rows->take(self::PAGE)->reverse()->values();
        }

        return response()->json([
            'messages'        => $this->present($rows, $me),
            'has_more'        => $hasMore,
            'first_unread_id' => $firstUnreadId,
            'updates'         => $updates,
            'typing'          => $this->typingNames($me, $peer, $group),
            'server_time'     => now()->toIso8601String(),
            'pinned'          => $peer === 0 && $group === 0 ? $this->pinned($me) : null,
        ] + $this->receipts($me, $peer, $group))->header('Cache-Control', 'no-store');
    }

    public function send(Request $r): JsonResponse
    {
        $me = (int) $r->user()->id;
        [$peer, $group] = $this->resolveChannel($r);

        $data = $r->validate([
            'body'        => ['nullable', 'string', 'max:2000', new NoEmoji(), new CleanText()],   // смайлы — только реакциями
            'reply_to_id' => ['nullable', 'integer'],
            'files'       => ['nullable', 'array', 'max:' . self::MAX_FILES],
            'files.*'     => ['file', 'max:' . self::MAX_FILE_KB, 'extensions:' . implode(',', self::FILE_EXTENSIONS)],
        ], [
            'body.max'          => 'Сообщение слишком длинное (до 2000 символов).',
            'files.max'         => 'Не больше ' . self::MAX_FILES . ' файлов в одном сообщении.',
            'files.*.max'       => 'Файл больше 20 МБ.',
            'files.*.extensions'=> 'Такой тип файла нельзя отправить.',
            'files.*.file'      => 'Файл не загрузился — попробуйте ещё раз.',
        ]);

        $body = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", (string) ($data['body'] ?? ''))));
        $files = $r->file('files', []);
        abort_if($body === '' && empty($files), 422, 'Введите сообщение или прикрепите файл.');

        // Ответить можно только на сообщение этого же канала
        $replyTo = null;
        if (!empty($data['reply_to_id'])) {
            $replyTo = $this->channelQuery($me, $peer, $group)->whereKey($data['reply_to_id'])->value('id');
            abort_unless($replyTo, 422, 'Сообщение, на которое вы отвечаете, не найдено.');
        }

        $message = DB::transaction(function () use ($me, $peer, $group, $body, $replyTo, $files) {
            $message = ChatMessage::create([
                'sender_id'    => $me,
                'recipient_id' => $peer ?: null,
                'group_id'     => $group ?: null,
                'reply_to_id'  => $replyTo,
                'body'         => $body !== '' ? $body : null,
            ]);
            foreach ($files as $file) {
                $this->storeAttachment($message, $file);
            }
            return $message;
        });

        ChatRead::markRead($me, $peer, $message->id, $group);
        $this->clearTyping($me, $peer, $group);

        return response()->json(['message' => $this->present(collect([$message->fresh()]), $me)[0]], 201);
    }

    /** Сохранить вложение: картинку — сжатой в WebP с миниатюрой, файл — как есть. */
    private function storeAttachment(ChatMessage $message, UploadedFile $file): ChatAttachment
    {
        $dir = 'chat/' . now()->format('Y/m');
        $base = Str::uuid()->toString();
        $name = Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 200, '');
        $mime = (string) $file->getMimeType();

        if (in_array($mime, self::RESIZABLE_IMAGES, true)) {
            try {
                $image = Image::decode($file->getRealPath());
                $image->scaleDown(self::IMAGE_MAX_SIDE, self::IMAGE_MAX_SIDE);
                $w = $image->width(); $h = $image->height();
                Storage::disk('local')->put("$dir/$base.webp", (string) $image->encode(new WebpEncoder(quality: 82)));

                $image->scaleDown(self::THUMB_MAX_SIDE, self::THUMB_MAX_SIDE);
                Storage::disk('local')->put("$dir/{$base}_t.webp", (string) $image->encode(new WebpEncoder(quality: 75)));

                return $message->attachments()->create([
                    'kind' => 'image', 'path' => "$dir/$base.webp", 'thumb_path' => "$dir/{$base}_t.webp",
                    'original_name' => $name, 'mime' => 'image/webp',
                    'size' => Storage::disk('local')->size("$dir/$base.webp"), 'width' => $w, 'height' => $h,
                ]);
            } catch (\Throwable $e) {
                report($e);   // не картинка, хоть и с таким расширением — сохраним как файл
            }
        }

        $ext = strtolower($file->getClientOriginalExtension()) ?: 'bin';
        $path = $file->storeAs($dir, "$base.$ext", 'local');

        return $message->attachments()->create([
            'kind' => 'file', 'path' => $path, 'thumb_path' => null,
            'original_name' => $name, 'mime' => $mime ?: 'application/octet-stream', 'size' => $file->getSize(),
        ]);
    }

    /** Вложение — только тем, кто видит сообщение; картинка показывается, файл скачивается. */
    public function attachment(Request $r, ChatAttachment $attachment, ?string $variant = null): Response
    {
        $message = $attachment->message;
        abort_unless($message && $message->visibleTo((int) $r->user()->id), 404);
        abort_if($message->created_at < now()->subDays(ChatMessage::RETENTION_DAYS), 404);

        $path = $variant === 'thumb' && $attachment->thumb_path ? $attachment->thumb_path : $attachment->path;
        abort_unless(Storage::disk('local')->exists($path), 404);

        $headers = ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=86400'];

        return $attachment->isImage() && !$r->boolean('download')
            ? Storage::disk('local')->response($path, $attachment->original_name, $headers + ['Content-Type' => $attachment->mime])
            : Storage::disk('local')->download($path, $attachment->original_name, $headers);
    }

    /** Поставить/снять реакцию. */
    public function react(Request $r, ChatMessage $message): JsonResponse
    {
        $me = (int) $r->user()->id;
        abort_unless($message->visibleTo($me), 404);
        abort_if($message->created_at < now()->subDays(ChatMessage::RETENTION_DAYS), 404);

        $emoji = $r->validate(['emoji' => ['required', 'string', Rule::in(ChatMessage::REACTIONS)]])['emoji'];

        // Одна реакция от человека на сообщение: тот же эмодзи — снять, другой — заменить
        DB::transaction(function () use ($message, $me, $emoji) {
            $mine = ChatReaction::where(['message_id' => $message->id, 'user_id' => $me])->lockForUpdate()->get();
            $same = $mine->firstWhere('emoji', $emoji);
            ChatReaction::whereIn('id', $mine->pluck('id'))->delete();
            if (! $same) {
                ChatReaction::create(['message_id' => $message->id, 'user_id' => $me, 'emoji' => $emoji]);
            }
        });

        $message->forceFill(['updated_at' => now()])->saveQuietly();   // чтобы другие забрали изменение опросом

        return response()->json(['id' => $message->id, 'reactions' => $this->present(collect([$message->fresh()]), $me)[0]['reactions']]);
    }

    /** «Печатает…» — отметка на несколько секунд. */
    public function typing(Request $r): JsonResponse
    {
        $me = (int) $r->user()->id;
        [$peer, $group] = $this->resolveChannel($r);
        $key = 'chat.typing.' . $this->channelKey($me, $peer, $group);

        $list = array_filter(Cache::get($key, []), fn ($row) => $row['until'] >= now()->getTimestamp());
        $list[$me] = ['name' => $this->displayNames([$me])[$me] ?? '', 'until' => now()->getTimestamp() + self::TYPING_SECONDS];
        Cache::put($key, $list, self::TYPING_SECONDS * 2);

        return response()->json(['ok' => true]);
    }

    private function clearTyping(int $me, int $peer, int $group = 0): void
    {
        $key = 'chat.typing.' . $this->channelKey($me, $peer, $group);
        $list = Cache::get($key, []);
        unset($list[$me]);
        Cache::put($key, $list, self::TYPING_SECONDS * 2);
    }

    public function read(Request $r): JsonResponse
    {
        [$peer, $group] = $this->resolveChannel($r);
        $lastId = (int) $r->input('last_id', 0);

        if ($lastId > 0) {
            ChatRead::markRead((int) $r->user()->id, $peer, $lastId, $group);
        }

        return response()->json(['ok' => true]);
    }
}
