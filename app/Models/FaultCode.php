<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Код неисправности из справочника. section_id = null — для всех участков. */
class FaultCode extends Model
{
    protected $fillable = ['code', 'description', 'hint', 'section_id', 'group_id', 'is_active', 'needs_review', 'created_by'];

    protected $casts = ['is_active' => 'boolean', 'needs_review' => 'boolean'];

    public function section(): BelongsTo
    {
        return $this->belongsTo(Section::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(FaultCodeGroup::class, 'group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
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
        // Сначала архив стёртого справочника (ещё не привязанные коды), поверх — действующие
        return FaultCodeArchive::query()->pluck('description', 'code')
            ->merge(static::query()->forSection($section)->where('is_active', true)->orderBy('code')->pluck('description', 'code'))
            ->map(fn ($d) => (string) $d)->all();
    }
}
