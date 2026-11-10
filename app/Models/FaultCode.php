<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Код неисправности из справочника. section_id = null — для всех участков. */
class FaultCode extends Model
{
    protected $fillable = ['code', 'description', 'section_id', 'is_active'];

    protected $casts = ['is_active' => 'boolean'];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    /** Коды, действующие на участке: общие + этого участка. */
    public function scopeForSection(Builder $q, ?Section $section): Builder
    {
        return $q->where(fn ($w) => $w->whereNull('section_id')
            ->when($section, fn ($x) => $x->orWhere('section_id', $section->id)));
    }

    /** [код => описание] для участка — для выпадающего списка и расшифровки. */
    public static function mapFor(?Section $section): array
    {
        return static::query()->forSection($section)->where('is_active', true)
            ->orderBy('code')->pluck('description', 'code')
            ->map(fn ($d) => (string) $d)->all();
    }
}
