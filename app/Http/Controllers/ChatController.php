<?php

namespace App\Http\Controllers;

use App\Models\ActiveWorkerSession;
use App\Models\ChatMessage;
use App\Models\ChatRead;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Чат: общий канал (peer = 0) и личные переписки (peer = id собеседника).
 * Доставка — опросом с клиента (resources/views/partials/chat-widget.blade.php).
 */
class ChatController extends Controller
{
    private const PAGE = 50;
    private const ONLINE_SECONDS = 180;

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
            ->mapWithKeys(fn ($u) => [$u->id => trim((string) ($u->short_name ?: $u->name))])
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

    private function channelQuery(int $me, int $peer)
    {
        return $peer === 0
            ? ChatMessage::query()->general()
            : ChatMessage::query()->between($me, $peer);
    }

    private function present(Collection $messages, int $me): array
    {
        $names = $this->displayNames($messages->pluck('sender_id'));

        return $messages->map(fn (ChatMessage $m) => [
            'id'          => $m->id,
            'body'        => $m->body,
            'at'          => $m->created_at?->toIso8601String(),
            'sender_id'   => $m->sender_id,
            'sender_name' => $names[$m->sender_id] ?? 'Удалён',
            'mine'        => $m->sender_id === $me,
        ])->values()->all();
    }

    /** Непрочитанные: общий канал + личные по собеседникам. */
    private function unread(int $me): array
    {
        $generalRead = (int) ChatRead::where('user_id', $me)->where('peer_id', 0)->value('last_read_id');

        $general = ChatMessage::query()->general()
            ->where('id', '>', $generalRead)
            ->where('sender_id', '!=', $me)
            ->count();

        $direct = DB::table('chat_messages as m')
            ->leftJoin('chat_reads as r', fn ($j) => $j->on('r.peer_id', '=', 'm.sender_id')->where('r.user_id', '=', $me))
            ->where('m.recipient_id', $me)
            ->whereRaw('m.id > COALESCE(r.last_read_id, 0)')
            ->groupBy('m.sender_id')
            ->selectRaw('m.sender_id as sid, COUNT(*) as c')
            ->pluck('c', 'sid')
            ->map(fn ($c) => (int) $c)
            ->all();

        return ['general' => $general, 'direct' => $direct];
    }

    // ---------------------------------------------------------------- actions

    /** Лёгкий опрос для значка на кнопке. */
    public function summary(Request $r): JsonResponse
    {
        $u = $this->unread((int) $r->user()->id);

        return response()->json([
            'unread' => $u['general'] + array_sum($u['direct']),
        ])->header('Cache-Control', 'no-store');
    }

    /** Список разговоров: общий канал + личные (последние сверху). */
    public function conversations(Request $r): JsonResponse
    {
        $me = (int) $r->user()->id;
        $unread = $this->unread($me);
        $online = array_flip($this->onlineUserIds());

        // Последнее сообщение в общем канале
        $lastGeneral = ChatMessage::query()->general()->latest('id')->first();

        // Последние сообщения личных переписок — берём свежие и группируем по собеседнику
        $recent = ChatMessage::query()
            ->where(fn ($w) => $w->where('recipient_id', $me)->orWhere(fn ($x) => $x->where('sender_id', $me)->whereNotNull('recipient_id')))
            ->latest('id')
            ->limit(1000)
            ->get(['id', 'sender_id', 'recipient_id', 'body', 'created_at']);

        $byPeer = [];
        foreach ($recent as $m) {
            $peer = $m->sender_id === $me ? $m->recipient_id : $m->sender_id;
            $byPeer[$peer] ??= $m;
        }
        $names = $this->displayNames(array_keys($byPeer) + [-1 => $lastGeneral?->sender_id]);

        $direct = [];
        foreach ($byPeer as $peer => $m) {
            $name = $names[$peer] ?? 'Удалён';
            $direct[] = [
                'peer'     => $peer,
                'name'     => $name,
                'initials' => $this->initials($name),
                'online'   => isset($online[$peer]),
                'unread'   => $unread['direct'][$peer] ?? 0,
                'last'     => ['body' => mb_strimwidth($m->body, 0, 90, '…'), 'at' => $m->created_at?->toIso8601String(), 'mine' => $m->sender_id === $me],
            ];
        }

        return response()->json([
            'general' => [
                'unread' => $unread['general'],
                'online' => count($online),
                'last'   => $lastGeneral ? [
                    'body'   => mb_strimwidth($lastGeneral->body, 0, 90, '…'),
                    'at'     => $lastGeneral->created_at?->toIso8601String(),
                    'author' => $lastGeneral->sender_id === $me ? 'Вы' : ($names[$lastGeneral->sender_id] ?? ''),
                ] : null,
            ],
            'direct' => $direct,
        ])->header('Cache-Control', 'no-store');
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
            $name = trim((string) ($u->short_name ?: $u->name));
            return ['peer' => $u->id, 'name' => $name, 'initials' => $this->initials($name), 'online' => isset($online[$u->id])];
        })
            // Сначала те, кто онлайн
            ->sortBy(fn ($u) => [$u['online'] ? 0 : 1, $u['name']])
            ->take(30)->values();

        return response()->json(['users' => $list]);
    }

    /** Сообщения канала: новые (after) или более ранние (before). */
    public function messages(Request $r): JsonResponse
    {
        $me = (int) $r->user()->id;
        $peer = $this->resolvePeer($r);
        $after = (int) $r->query('after', 0);
        $before = (int) $r->query('before', 0);

        $q = $this->channelQuery($me, $peer);

        if ($after > 0) {
            $rows = $q->where('id', '>', $after)->orderBy('id')->limit(200)->get();
            $hasMore = null;
        } else {
            if ($before > 0) $q->where('id', '<', $before);
            $rows = $q->orderByDesc('id')->limit(self::PAGE + 1)->get();
            $hasMore = $rows->count() > self::PAGE;
            $rows = $rows->take(self::PAGE)->reverse()->values();
        }

        return response()->json([
            'messages' => $this->present($rows, $me),
            'has_more' => $hasMore,
        ])->header('Cache-Control', 'no-store');
    }

    public function send(Request $r): JsonResponse
    {
        $me = (int) $r->user()->id;
        $peer = $this->resolvePeer($r);

        $data = $r->validate([
            'body' => ['required', 'string', 'max:2000'],
        ], [
            'body.required' => 'Введите сообщение.',
            'body.max'      => 'Сообщение слишком длинное (до 2000 символов).',
        ]);

        $body = trim(preg_replace("/\n{3,}/", "\n\n", str_replace("\r\n", "\n", $data['body'])));
        abort_if($body === '', 422, 'Введите сообщение.');

        $message = ChatMessage::create([
            'sender_id'    => $me,
            'recipient_id' => $peer ?: null,
            'body'         => $body,
        ]);

        ChatRead::markRead($me, $peer, $message->id);

        return response()->json(['message' => $this->present(collect([$message]), $me)[0]], 201);
    }

    public function read(Request $r): JsonResponse
    {
        $peer = $this->resolvePeer($r);
        $lastId = (int) $r->input('last_id', 0);

        if ($lastId > 0) {
            ChatRead::markRead((int) $r->user()->id, $peer, $lastId);
        }

        return response()->json(['ok' => true]);
    }
}
