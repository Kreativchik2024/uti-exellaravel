<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\FaultCode;
use App\Models\FaultCodeArchive;
use App\Models\FaultCodeGroup;
use App\Models\Section;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Справочник кодов неисправностей в формах учёта:
 *  - коды вкладки = коды подгрупп и разделов, привязанных к вкладке, + общие;
 *  - поиск по коду и по описанию «по смыслу» (регистр, окончания слов, порядок не важны);
 *  - вписали описание вместо кода — подбирается похожий код, а если похожего нет,
 *    создаётся новый в подходящем разделе со следующим номером (C(3).3.11) и пометкой «проверить».
 */
class FaultCodes
{
    /** Похожесть, начиная с которой описание считается уже существующей неисправностью. */
    public const SAME = 0.75;

    public function __construct(private SectionContext $sections) {}

    // ================================================================ коды вкладки

    /** Подгруппы и разделы, коды которых выпадают во вкладке. */
    public function groupsForSheet(string $sheet): Collection
    {
        return FaultCodeGroup::tree()->filter(fn ($g) => $g->coversSheet($sheet))
            ->sortBy(fn ($g) => $g->sortKey())->values();
    }

    /** Действующие коды для вкладки: её категорий и общие. */
    public function codesForSheet(string $sheet): Collection
    {
        $tree = FaultCodeGroup::tree();
        $groupIds = $tree->filter(fn ($g) => $g->coversSheet($sheet))->keys();

        // Коды без раздела — только в ПГП (борт целиком) или если у вкладки разделов нет вовсе
        $withLoose = $sheet === 'pgp' || $groupIds->isEmpty();
        $codes = FaultCode::query()
            ->forSection($this->sections->current())
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereIn('group_id', $groupIds)->when($withLoose, fn ($w) => $w->orWhereNull('group_id')))
            ->get();
        FaultCodeGroup::attachTo($codes, $tree);

        // Как в таблице: по уровням, внутри — по номеру кода (C(3).3.2 раньше C(3).3.10)
        return $codes->sort(fn ($a, $b) => strcmp($a->group?->sortKey() ?? '~', $b->group?->sortKey() ?? '~') ?: strnatcasecmp($a->code, $b->code))->values();
    }

    /** [код => описание] — для выпадающего списка. */
    public function mapForSheet(string $sheet): array
    {
        return $this->codesForSheet($sheet)->mapWithKeys(fn ($c) => [$c->code => (string) $c->description])->all();
    }

    /** Список для поля записи: [{code, description, hint, group, score}]. */
    public function optionsForSheet(string $sheet): array
    {
        return $this->codesForSheet($sheet)->map(fn ($c) => $this->item($c, 1.0))->values()->all();
    }

    // ================================================================ поиск

    /**
     * Подсказки для поля: по началу кода и по смыслу описания.
     * @return array{items: array, new: ?array}
     */
    public function suggest(string $sheet, string $q, int $limit = 15): array
    {
        $q = trim($q);
        $codes = $this->codesForSheet($sheet);
        if ($q === '') {
            return ['items' => $codes->take($limit)->map(fn ($c) => $this->item($c, 1.0))->values()->all(), 'new' => null];
        }

        $scored = $codes->map(function ($c) use ($q) {
            $score = str_starts_with(mb_strtolower($c->code), mb_strtolower($q)) ? 1.0 + (mb_strlen($q) / 100) : self::similarity($q, (string) $c->description);
            // Все слова запроса есть в описании («кулер» → «Неисправен кулер охлаждения») — точно подходит
            $words = self::stems($q);
            $desc = self::stems((string) $c->description . ' ' . $c->hint);
            if ($words && ! array_diff($words, $desc)) $score = max($score, 0.6 + 0.3 * count($words) / max(1, count($desc)));
            return [$c, $score];
        })->filter(fn ($x) => $x[1] >= 0.34)->sortByDesc(fn ($x) => $x[1])->take($limit);

        $best = $scored->first()[1] ?? 0;
        $new = null;
        if (self::looksLikeDescription($q) && $best < self::SAME) {
            $group = $this->groupForNew($sheet, $q, false);
            $new = ['description' => self::cleanDescription($q), 'group' => $group?->label() ?? $this->sheetTitle($sheet),
                    'code' => $group ? $this->nextCode($group) : self::derivePrefix($this->sheetTitle($sheet)) . '.1'];
        }

        return ['items' => $scored->map(fn ($x) => $this->item($x[0], $x[1]))->values()->all(), 'new' => $new];
    }

    private function item(FaultCode $c, float $score): array
    {
        return ['code' => $c->code, 'description' => (string) $c->description, 'hint' => (string) $c->hint,
                'group' => $c->group?->label(), 'path' => $c->group ? array_map(fn ($g) => $g->name, $c->group->path()) : [],
                'score' => round(min(1, $score), 2)];
    }

    // ================================================================ сохранение записи

    /**
     * Что сохранить в поле «Код неисправности»: код как есть, код похожей неисправности
     * или новый код, если вписано описание новой неисправности.
     */
    public function resolve(string $sheet, ?string $value, ?int $userId = null): ?string
    {
        $value = trim((string) $value);
        if ($value === '') return null;

        $codes = $this->codesForSheet($sheet);
        $exact = $codes->first(fn ($c) => mb_strtolower($c->code) === mb_strtolower($value))
            ?? FaultCode::query()->whereRaw('LOWER(code) = ?', [mb_strtolower($value)])->first();
        if ($exact) return $exact->code;

        if (! self::looksLikeDescription($value)) return $value;   // незнакомый короткий код — как вписали

        // Описание: есть похожее — берём его код. Подходят несколько разных («Отсутствует ПО» есть
        // у камеры, роутера, хаба…) — не угадываем, а просим выбрать.
        $matches = $codes->map(fn ($c) => [$c, self::similarity($value, (string) $c->description)])
            ->filter(fn ($x) => $x[1] >= self::SAME)->sortByDesc(fn ($x) => $x[1])->values();
        if ($matches->isNotEmpty()) {
            $top = $matches[0][1];
            $tied = $matches->filter(fn ($x) => $x[1] >= $top - 0.001);
            if ($tied->count() > 1) {
                throw \Illuminate\Validation\ValidationException::withMessages(['fault_code' =>
                    'Под описание подходит несколько кодов — выберите нужный: ' . $tied->take(6)->map(fn ($x) => $x[0]->code . ' (' . ($x[0]->group?->label() ?? 'общий') . ')')->implode(', ')]);
            }
            return $matches[0][0]->code;
        }

        return $this->createFromDescription($sheet, $value, $userId)->code;
    }

    /** Новая неисправность → код в категории вкладки со следующим номером. */
    public function createFromDescription(string $sheet, string $description, ?int $userId = null): FaultCode
    {
        return DB::transaction(function () use ($sheet, $description, $userId) {
            $group = $this->groupForNew($sheet, $description, true);
            FaultCodeGroup::whereKey($group->id)->lockForUpdate()->first();   // номера не повторяются при одновременном добавлении

            $code = FaultCode::create([
                'code'         => $this->nextCode($group),
                'description'  => self::cleanDescription($description),
                'group_id'     => $group->id,
                'section_id'   => $group->section_id,
                'is_active'    => true,
                'needs_review' => true,
                'created_by'   => $userId,
            ]);
            AuditLog::write('fault_codes', 'created', "Новый код {$code->code} «{$code->description}» добавлен автоматически ({$this->sheetTitle($sheet)})",
                ['subject_type' => 'fault_code', 'subject_id' => $code->id, 'section_id' => $group->section_id]);

            return $code;
        });
    }

    /**
     * Категория для новой неисправности: единственная категория вкладки, а если их несколько —
     * та, где есть самые похожие неисправности. Категорий нет — создаётся категория вкладки.
     */
    public function groupForNew(string $sheet, string $description, bool $create): ?FaultCodeGroup
    {
        // Куда можно добавить: нижние уровни (без вложенных)
        $parents = FaultCodeGroup::whereNotNull('parent_id')->pluck('parent_id')->unique()->flip();
        $groups = $this->groupsForSheet($sheet)->reject(fn ($g) => isset($parents[$g->id]))->values();
        if ($groups->count() === 1) return $groups->first();
        if ($groups->count() > 1) {
            return $groups->sortByDesc(fn ($g) => $g->codes->max(fn ($c) => self::similarity($description, (string) $c->description)) ?? 0)->first();
        }
        if (! $create) return null;

        $title = $this->sheetTitle($sheet);
        $prefix = self::derivePrefix($title);
        for ($i = 2; FaultCodeGroup::where('prefix', $prefix)->exists(); $i++) $prefix = self::derivePrefix($title) . $i;

        $sectionSlug = config("sheets.map.$sheet.section");

        return FaultCodeGroup::create([
            'name' => $title, 'prefix' => $prefix, 'sheets' => [$sheet], 'sort' => 999,
            'section_id' => $sectionSlug ? Section::where('slug', $sectionSlug)->value('id') : null,
        ]);
    }

    /**
     * Следующий код группы по её же схеме: основа — общая часть кодов группы
     * (C(3).3.1…C(3).3.10 → основа C(3).3, следующий C(3).3.11; R.1.11 → R.1.12).
     * В пустой группе — её префикс: C(3).6 → C(3).6.1.
     */
    public function nextCode(FaultCodeGroup $group): string
    {
        $bases = [];
        foreach ($group->codes()->pluck('code') as $code) {
            if (preg_match('/^(.+)\.(\d+)$/u', $code, $m)) {
                $bases[$m[1]] ??= ['count' => 0, 'max' => 0];
                $bases[$m[1]]['count']++;
                $bases[$m[1]]['max'] = max($bases[$m[1]]['max'], (int) $m[2]);
            }
        }
        if ($bases) {
            uasort($bases, fn ($a, $b) => $b['count'] <=> $a['count']);
            $base = array_key_first($bases);
            $next = $bases[$base]['max'] + 1;
        } else {
            $base = $group->prefix;
            $next = 1;
        }
        // Номер может быть уже занят кодом из другой группы — берём следующий свободный
        while (FaultCode::where('code', "$base.$next")->exists()) $next++;

        return "$base.$next";
    }

    /** Основа кодов группы (для префикса): самая частая часть до последнего номера. */
    public static function baseOf(array $codes): ?string
    {
        $count = [];
        foreach ($codes as $code) {
            if (preg_match('/^(.+)\.(\d+)$/u', (string) $code, $m)) $count[$m[1]] = ($count[$m[1]] ?? 0) + 1;
        }
        arsort($count);

        return array_key_first($count);
    }

    private function sheetTitle(string $sheet): string
    {
        return (string) (config("sheets.map.$sheet.title") ?? $sheet);
    }

    // ================================================================ текст

    /** Описание, а не код: есть пробел или длиннее 12 символов. */
    public static function looksLikeDescription(string $value): bool
    {
        $value = trim($value);

        return mb_strlen($value) >= 4 && (str_contains($value, ' ') || mb_strlen($value) > 12) && preg_match('/\p{L}{3,}/u', $value);
    }

    public static function cleanDescription(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value));

        return mb_strtoupper(mb_substr($value, 0, 1)) . mb_substr($value, 1, 254);
    }

    /** Основы слов: «Нет связи с модулем» → [нет, связ, модул]. */
    public static function stems(string $text): array
    {
        $text = str_replace('ё', 'е', mb_strtolower($text));
        $words = preg_split('/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $stop = ['и', 'в', 'на', 'с', 'со', 'по', 'к', 'о', 'об', 'от', 'для', 'из', 'у', 'при', 'не'];
        $out = [];
        foreach ($words as $w) {
            if (in_array($w, $stop, true)) continue;
            $out[] = mb_strlen($w) > 5 ? mb_substr($w, 0, max(4, mb_strlen($w) - 2)) : $w;   // грубое отсечение окончаний
        }

        return array_values(array_unique($out));
    }

    /** Похожесть двух описаний 0…1 (совпадение основ слов, с учётом начала слова). */
    public static function similarity(string $a, string $b): float
    {
        $sa = self::stems($a);
        $sb = self::stems($b);
        if (! $sa || ! $sb) return 0.0;

        $hits = 0.0;
        foreach ($sa as $x) {
            $best = 0.0;
            foreach ($sb as $y) {
                if ($x === $y) { $best = 1.0; break; }
                $n = min(mb_strlen($x), mb_strlen($y));
                if ($n >= 4 && mb_substr($x, 0, $n) === mb_substr($y, 0, $n)) $best = max($best, 0.85);   // «камер» ~ «камера»
            }
            $hits += $best;
        }

        return round($hits / max(count($sa), count($sb)), 3);
    }

    /** Префикс категории: «Кросс 3» → К3, «ПГП (борта)» → ПГП, «Модуль связи» → МС. */
    public static function derivePrefix(string $title): string
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', $title, -1, PREG_SPLIT_NO_EMPTY) ?: ['КОД'];
        $digits = implode('', array_filter($words, fn ($w) => ctype_digit($w)));
        $letters = array_values(array_filter($words, fn ($w) => ! ctype_digit($w)));
        $first = $letters[0] ?? 'КОД';
        if (mb_strlen($first) <= 5 && $first === mb_strtoupper($first)) return $first . $digits;   // уже сокращение: ПГП

        return mb_strtoupper(implode('', array_map(fn ($w) => mb_substr($w, 0, 1), array_slice($letters, 0, 3)))) . $digits;
    }

    // ================================================================ стереть и привязать заново

    /**
     * Стереть справочник: коды и уровни удаляются, в записях учёта коды остаются как были.
     * Старые коды с описаниями уходят в архив — по нему записи привяжутся к новым кодам.
     * @return int сколько кодов стёрто
     */
    public function wipe(): int
    {
        return DB::transaction(function () {
            $tree = FaultCodeGroup::tree();
            $codes = FaultCode::all();
            FaultCodeGroup::attachTo($codes, $tree);
            foreach ($codes as $c) {
                FaultCodeArchive::updateOrCreate(['code' => $c->code], [
                    'description' => $c->description, 'hint' => $c->hint, 'group_label' => $c->group?->label(),
                ]);
            }
            FaultCode::query()->delete();
            FaultCodeGroup::query()->whereNotNull('parent_id')->update(['parent_id' => null]);
            FaultCodeGroup::query()->delete();

            return $codes->count();
        });
    }

    /**
     * Привязать коды из архива к загруженному справочнику:
     *  - такой же код есть — записи уже указывают на него;
     *  - код другой, но описание то же или похожее (и вариант один) — в записях код меняется на новый.
     * Не нашлось пары — код остаётся в архиве (в записях — как был, с расшифровкой из архива).
     * @return array{linked: int, records: int, left: string[]}
     */
    public function relinkArchive(): array
    {
        $archive = FaultCodeArchive::all();
        if ($archive->isEmpty()) return ['linked' => 0, 'records' => 0, 'left' => []];

        $codes = FaultCode::all();
        FaultCodeGroup::attachTo($codes);
        $byCode = $codes->keyBy(fn ($c) => mb_strtolower($c->code));

        $linked = $records = 0;
        $left = [];
        foreach ($archive as $old) {
            $target = $byCode[mb_strtolower($old->code)] ?? null;

            if (! $target && trim((string) $old->description) !== '') {
                // Похожие по описанию; несколько — решает похожесть пути (МИНИ ПК ↔ МИНИ ПК)
                $cands = $codes->map(fn ($c) => [$c, self::similarity((string) $old->description, (string) $c->description),
                        self::similarity((string) $old->group_label, (string) $c->group?->label())])
                    ->filter(fn ($x) => $x[1] >= self::SAME)
                    ->sort(fn ($a, $b) => [$b[1], $b[2]] <=> [$a[1], $a[2]])->values();
                $first = $cands[0] ?? null;
                $second = $cands[1] ?? null;
                if ($first && (! $second || [$first[1], $first[2]] > [$second[1], $second[2]])) $target = $first[0];
            }

            if (! $target) { $left[] = $old->code; continue; }

            if ($target->code !== $old->code) {
                $records += $this->replaceInRecords($old->code, $target->code);
            }
            if (! $target->hint && $old->hint) $target->update(['hint' => $old->hint]);
            $old->delete();
            $linked++;
        }

        return ['linked' => $linked, 'records' => $records, 'left' => $left];
    }

    /** Заменить код во всех вкладках учёта. @return int сколько записей исправлено */
    public function replaceInRecords(string $from, string $to): int
    {
        $changed = 0;
        foreach (config('sheets.map') as $cfg) {
            if (! empty($cfg['model']) && isset($cfg['fields']['fault_code'])) {
                $changed += $cfg['model']::query()->withoutGlobalScopes()->where('fault_code', $from)->update(['fault_code' => $to]);
            }
        }

        return $changed;
    }
}
