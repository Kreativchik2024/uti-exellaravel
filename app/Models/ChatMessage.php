<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatMessage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['sender_id', 'recipient_id', 'body'];

    protected $casts = ['created_at' => 'datetime'];

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
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
}
