<?php

namespace App\Support;

/**
 * Справочник типов бортов (config/board_types.php) и определение типа по номеру борта.
 */
class BoardTypes
{
    /** Латинские буквы, которые на клавиатуре легко спутать с русскими. */
    private const HOMOGLYPHS = [
        'A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н', 'K' => 'К',
        'M' => 'М', 'O' => 'О', 'P' => 'Р', 'T' => 'Т', 'X' => 'Х', 'Y' => 'У',
    ];

    /** Все типы: код => ['label' => ..., 'match' => [...]]. */
    public static function all(): array
    {
        return self::fromFile();
    }

    /** Коды типов в порядке из конфига. */
    public static function codes(): array
    {
        return array_keys(self::all());
    }

    /** Подписи: код => подпись. */
    public static function labels(): array
    {
        return array_map(fn ($t) => $t['label'] ?? '', self::all());
    }

    public static function label(?string $code): ?string
    {
        if ($code === null || $code === '') return null;
        return self::all()[$code]['label'] ?? $code;
    }

    /**
     * Тип по номеру борта: «УМН-12», «мн 7», «RA-СОН-3» → УМН / МН / СОН.
     * null — если в номере нет ни одного известного фрагмента.
     */
    public static function detect(?string $boardNumber): ?string
    {
        $value = self::normalize((string) $boardNumber);
        if ($value === '') return null;

        foreach (self::patterns() as [$fragment, $code]) {
            if (mb_strpos($value, $fragment) !== false) return $code;
        }

        return null;
    }

    /**
     * Фрагменты для поиска в номере: [[фрагмент, код], ...], длинные первыми
     * («УМН» содержит «МН»). Тот же список уходит в форму для подстановки на лету.
     */
    public static function patterns(): array
    {
        $patterns = [];
        foreach (self::all() as $code => $type) {
            foreach (($type['match'] ?? [$code]) as $fragment) {
                $fragment = self::normalize((string) $fragment);
                if ($fragment !== '') $patterns[] = [$fragment, (string) $code];
            }
        }
        usort($patterns, fn ($a, $b) => mb_strlen($b[0]) <=> mb_strlen($a[0]));

        return $patterns;
    }

    /** Латинские буквы-двойники → русские (для формы). */
    public static function homoglyphs(): array
    {
        return self::HOMOGLYPHS;
    }

    private static function normalize(string $value): string
    {
        return strtr(mb_strtoupper(trim($value)), self::HOMOGLYPHS);
    }

    /**
     * Читаем файл напрямую: класс используется и внутри config/sheets.php,
     * когда остальной конфиг может быть ещё не загружен.
     */
    private static function fromFile(): array
    {
        if (app()->bound('config') && config()->has('board_types.types')) {
            return (array) config('board_types.types');
        }
        return (require dirname(__DIR__, 2) . '/config/board_types.php')['types'] ?? [];
    }
}
