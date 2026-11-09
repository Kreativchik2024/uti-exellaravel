<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Докуда пользователь прочитал канал (peer_id = 0 — общий, иначе собеседник). */
class ChatRead extends Model
{
    public const CREATED_AT = null;

    protected $fillable = ['user_id', 'peer_id', 'last_read_id'];

    /** Сдвинуть отметку «прочитано» вперёд (назад — никогда). */
    public static function markRead(int $userId, int $peerId, int $messageId): void
    {
        $row = static::firstOrNew(['user_id' => $userId, 'peer_id' => $peerId]);
        if ((int) $row->last_read_id < $messageId) {
            $row->last_read_id = $messageId;
            $row->save();
        }
    }
}
