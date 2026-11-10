<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Фото к записи учёта. При удалении удаляются и файлы. */
class RecordPhoto extends Model
{
    /** Сколько фото можно прикрепить к одной записи. */
    public const MAX_PER_RECORD = 10;

    protected $fillable = ['sheet', 'record_id', 'path', 'thumb_path', 'original_name', 'size', 'width', 'height', 'uploaded_by'];

    protected static function booted(): void
    {
        static::deleting(fn (self $p) => Storage::disk('local')->delete(array_filter([$p->path, $p->thumb_path])));
    }

    public function scopeOf(Builder $q, string $sheet, int $recordId): Builder
    {
        return $q->where('sheet', $sheet)->where('record_id', $recordId)->orderBy('id');
    }

    public function url(bool $thumb = false): string
    {
        return route('records.photo', array_filter(['photo' => $this->id, 'variant' => $thumb ? 'thumb' : null]));
    }
}
