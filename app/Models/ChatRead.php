<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Докуда пользователь прочитал канал:
 * peer_id = 0, group_id = 0 — общий; peer_id — личная переписка с собеседником; group_id — группа.
 */
class ChatRead extends Model
{
    public const CREATED_AT = null;

    protected $fillable = ['user_id', 'peer_id', 'group_id', 'last_read_id'];

    /** Сдвинуть отметку «прочитано» вперёд (назад — никогда). */
    public static function markRead(int $userId, int $peerId, int $messageId, int $groupId = 0): void
    {
        $row = static::firstOrNew(['user_id' => $userId, 'peer_id' => $groupId ? 0 : $peerId, 'group_id' => $groupId]);
        if ((int) $row->last_read_id < $messageId) {
            $row->last_read_id = $messageId;
            $row->save();
        }
    }
}
