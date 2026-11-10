<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Вложение сообщения чата: картинка (с миниатюрой) или файл. Хранится на диске local. */
class ChatAttachment extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['message_id', 'kind', 'path', 'thumb_path', 'original_name', 'mime', 'size', 'width', 'height'];

    protected static function booted(): void
    {
        // Удаляем файлы с диска вместе с записью
        static::deleting(function (ChatAttachment $a) {
            Storage::disk('local')->delete(array_filter([$a->path, $a->thumb_path]));
        });
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function isImage(): bool
    {
        return $this->kind === 'image';
    }
}
