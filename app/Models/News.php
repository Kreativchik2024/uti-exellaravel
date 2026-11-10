<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class News extends Model
{
    protected $table = 'news';

    protected $fillable = [
        'section_id', 'title', 'body', 'is_pinned', 'is_published', 'author_id', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned'    => 'boolean',
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    public function images()
    {
        return $this->hasMany(NewsImage::class)->orderBy('sort_order');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** Участок новости; NULL — новость для всех участков. */
    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    /** Новости участка + общие («для всех участков»). */
    public function scopeForSection($q, ?Section $section)
    {
        return $q->where(fn ($w) => $w->whereNull('section_id')
            ->when($section, fn ($x) => $x->orWhere('section_id', $section->id)));
    }

    public function scopePublished($q)
    {
        return $q->where('is_published', true)->where('published_at', '<=', now());
    }
}