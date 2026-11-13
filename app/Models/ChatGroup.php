<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/** Групповая беседа чата. */
class ChatGroup extends Model
{
    /** Сколько человек может быть в одной группе. */
    public const MAX_MEMBERS = 100;

    protected $fillable = ['name', 'created_by'];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'chat_group_members', 'group_id', 'user_id')
            ->withPivot(['added_by', 'created_at']);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'group_id');
    }

    /** Группы, где пользователь — участник. */
    public function scopeWithMember(Builder $q, int $userId): Builder
    {
        return $q->whereExists(fn ($w) => $w->from('chat_group_members')
            ->whereColumn('chat_group_members.group_id', 'chat_groups.id')
            ->where('chat_group_members.user_id', $userId));
    }

    public function hasMember(int $userId): bool
    {
        return DB::table('chat_group_members')
            ->where('group_id', $this->id)->where('user_id', $userId)->exists();
    }

    public function memberIds(): array
    {
        return DB::table('chat_group_members')->where('group_id', $this->id)
            ->orderBy('id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();
    }

    /** Переименовывать и исключать других может создатель группы, мастер и админ. */
    public function canManage(User $user): bool
    {
        return (int) $this->created_by === (int) $user->id || $user->canManageContent();
    }

    /** Добавить участников (уже состоящих пропускаем). Возвращает, сколько добавлено. */
    public function addMembers(array $userIds, ?int $addedBy): int
    {
        $new = array_values(array_diff(array_unique(array_map('intval', $userIds)), $this->memberIds()));
        if (!$new) return 0;

        $now = now();
        $this->members()->attach(array_fill_keys($new, ['added_by' => $addedBy, 'created_at' => $now]));

        // Новичку история группы видна, но не считается непрочитанной
        $lastId = (int) $this->messages()->max('id');
        if ($lastId > 0) {
            foreach ($new as $uid) ChatRead::markRead($uid, 0, $lastId, $this->id);
        }

        return count($new);
    }
}
