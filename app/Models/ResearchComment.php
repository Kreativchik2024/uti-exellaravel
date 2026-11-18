<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Комментарий к исследованию. Комментарии не правятся — история остаётся как была. */
class ResearchComment extends Model
{
    protected $fillable = ['body'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function research(): BelongsTo
    {
        return $this->belongsTo(ResearchRecord::class, 'research_record_id');
    }
}
