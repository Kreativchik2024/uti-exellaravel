<?php

namespace App\Http\Controllers;

use App\Models\ChatGroup;
use App\Models\ChatRead;
use App\Models\User;
use App\Rules\CleanText;
use App\Rules\NoEmoji;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Чат: групповые беседы — создать, участники, переименовать (сообщения — ChatController). */
class ChatGroupController extends Controller
{
    use Concerns\ChatPeople;

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
    public function create(Request $r): JsonResponse
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

    public function show(Request $r, ChatGroup $group): JsonResponse
    {
        return response()->json($this->groupInfo($this->findMyGroup($r, $group), $r));
    }

    /** Добавить в группу сразу нескольких участников (может любой участник). */
    public function addMembers(Request $r, ChatGroup $group): JsonResponse
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
    public function removeMember(Request $r, ChatGroup $group): JsonResponse
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
    public function rename(Request $r, ChatGroup $group): JsonResponse
    {
        $group = $this->findMyGroup($r, $group);
        abort_unless($group->canManage($r->user()), 403, 'Переименовать группу может только её создатель.');

        $group->update(['name' => $this->groupName($r)]);

        return response()->json($this->groupInfo($group, $r));
    }
}
