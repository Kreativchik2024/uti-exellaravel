<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Уровень справочника кодов неисправностей. Уровни вкладываются друг в друга (parent_id),
 * как Р1 → Р2 → Р3 в таблице предприятия: «МОТОРАМА» → «Кросс 3 (Cross 3)» → «МИНИ ПК».
 * prefix — основа кода (C(3).3 → коды C(3).3.1, C(3).3.2…).
 * Вкладки: коды уровня выпадают во вкладках, отмеченных у него или у любого уровня выше.
 */
class FaultCodeGroup extends Model
{
    protected $fillable = ['parent_id', 'name', 'sort', 'prefix', 'sheets', 'section_id'];

    protected $casts = ['sheets' => 'array'];

    public function codes(): HasMany
    {
        return $this->hasMany(FaultCode::class, 'group_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id')->orderBy('sort')->orderBy('name');
    }

    /**
     * Все уровни одним запросом, у каждого проставлен parent по цепочке — путь и вкладки
     * считаются без лишних запросов. Ключи — id.
     */
    public static function tree(bool $withCount = false): Collection
    {
        $all = self::query()->when($withCount, fn ($q) => $q->withCount('codes'))->get()->keyBy('id');
        foreach ($all as $g) $g->setRelation('parent', $g->parent_id ? ($all[$g->parent_id] ?? null) : null);

        return $all;
    }

    /** Проставить кодам их уровень из дерева (с цепочкой родителей). */
    public static function attachTo(iterable $codes, ?Collection $tree = null): void
    {
        $tree ??= self::tree();
        foreach ($codes as $c) $c->setRelation('group', $c->group_id ? ($tree[$c->group_id] ?? null) : null);
    }

    /** Цепочка от верхнего уровня до этого: [МОТОРАМА, Кросс 3, МИНИ ПК]. */
    public function path(): array
    {
        $path = [];
        for ($g = $this, $guard = 0; $g && $guard < 20; $g = $g->parent, $guard++) array_unshift($path, $g);

        return $path;
    }

    public function depth(): int
    {
        return count($this->path());
    }

    /** id этого уровня и всех вложенных. */
    public function selfAndDescendantIds(Collection $tree): array
    {
        $ids = [$this->id];
        for ($i = 0; $i < count($ids); $i++) {
            foreach ($tree as $g) if ($g->parent_id === $ids[$i] && ! in_array($g->id, $ids, true)) $ids[] = $g->id;
        }

        return $ids;
    }

    /** Вкладки — свои и всех уровней выше. */
    public function effectiveSheets(): array
    {
        $sheets = [];
        foreach ($this->path() as $g) $sheets = array_merge($sheets, (array) $g->sheets);

        return array_values(array_unique($sheets));
    }

    public function coversSheet(string $sheet): bool
    {
        return in_array($sheet, $this->effectiveSheets(), true);
    }

    /** «МОТОРАМА · Кросс 3 (Cross 3) · МИНИ ПК» */
    public function label(): string
    {
        return implode(' · ', array_map(fn ($g) => $g->name, $this->path()));
    }

    /** Ключ сортировки: по порядку уровней сверху вниз, как в таблице. */
    public function sortKey(): string
    {
        return implode('|', array_map(fn ($g) => sprintf('%05d %s', (int) $g->sort, mb_strtolower($g->name)), $this->path()));
    }

    /** Имя с номером порядка, как в таблице предприятия: «01_МОТОРАМА». */
    public function numberedName(): string
    {
        return $this->sort && $this->sort < 999 ? sprintf('%02d_%s', $this->sort, $this->name) : $this->name;
    }
}
