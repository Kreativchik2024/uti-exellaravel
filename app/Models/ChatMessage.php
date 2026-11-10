<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatMessage extends Model
{
    /** Сколько дней хранится история (старше — не показывается и удаляется chat:prune). */
    public const RETENTION_DAYS = 7;

    /** Набор реакций (как быстрые реакции в Telegram). */
    public const REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🔥', '✅', '👀'];

    protected $fillable = ['sender_id', 'recipient_id', 'reply_to_id', 'body'];

    protected $casts = ['created_at' => 'datetime', 'updated_at' => 'datetime', 'pinned_at' => 'datetime'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatAttachment::class, 'message_id');
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(ChatReaction::class, 'message_id');
    }

    /** Общий канал. */
    public function scopeGeneral(Builder $q): Builder
    {
        return $q->whereNull('recipient_id');
    }

    /** Личная переписка двух пользователей (в обе стороны). */
    public function scopeBetween(Builder $q, int $a, int $b): Builder
    {
        return $q->where(fn ($w) => $w
            ->where(fn ($x) => $x->where('sender_id', $a)->where('recipient_id', $b))
            ->orWhere(fn ($x) => $x->where('sender_id', $b)->where('recipient_id', $a)));
    }

    /** Только последние RETENTION_DAYS дней — даже если очистка по расписанию не запускалась. */
    public function scopeRecent(Builder $q): Builder
    {
        return $q->where('chat_messages.created_at', '>=', now()->subDays(self::RETENTION_DAYS));
    }

    /** Может ли пользователь видеть сообщение (общий канал — все; личное — участники). */
    public function visibleTo(int $userId): bool
    {
        return $this->recipient_id === null
            || $this->sender_id === $userId
            || $this->recipient_id === $userId;
    }
}
