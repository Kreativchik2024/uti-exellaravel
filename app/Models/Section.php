<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Участок производства. Вкладки учёта участка задаются в config/sheets.php
 * ключом 'section' => slug участка.
 */
class Section extends Model
{
    /** Участок, к которому относятся вкладки без явного 'section' в конфиге. */
    public const DEFAULT_SLUG = 'ispytaniya';

    protected $fillable = ['slug', 'name', 'sort_order', 'is_active', 'has_firmware'];

    protected $casts = [
        'is_active'    => 'boolean',
        'has_firmware' => 'boolean',
    ];

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class);
    }

    public function news(): HasMany
    {
        return $this->hasMany(News::class);
    }

    public function scopeActive(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    public function scopeOrdered(Builder $q): Builder
    {
        return $q->orderBy('sort_order')->orderBy('name');
    }

    /** Вкладки учёта участка из config/sheets.php: [slug => cfg]. */
    public function sheets(): array
    {
        return array_filter(
            config('sheets.map', []),
            fn ($cfg) => ($cfg['section'] ?? self::DEFAULT_SLUG) === $this->slug
        );
    }
}
