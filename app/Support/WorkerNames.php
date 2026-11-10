<?php

namespace App\Support;

use App\Models\Employee;

/**
 * Распознавание исполнителя по написанию ФИО в записях.
 *
 * «Иванов И.И.», «Иванов И. И.», «иванов  ии», «И.И. Иванов», «Иванов Иван Иванович» — один человек:
 * регистр, ё/е, пробелы, точки и порядок слов не важны, полное имя сводится к инициалам.
 * Неполное написание («Иванов И.», «Иванов») относится к сотруднику из справочника,
 * если такой сотрудник с этой фамилией один. Показывается имя из справочника.
 */
class WorkerNames
{
    /** @var array<string, string> ключ => ФИО из справочника */
    private array $directory;

    /** @var array<string, array<string, int>> ключ => [написание => сколько раз] */
    private array $seen = [];

    public function __construct(?array $directory = null)
    {
        $this->directory = $directory ?? self::loadDirectory();
    }

    /** Ключ написания: «фамилия ии» или '' если это не ФИО («-», «нет», цифры). */
    public static function key(string $name): string
    {
        $s = mb_strtolower(str_replace(["\xC2\xA0", 'Ё'], [' ', 'Е'], $name));
        $s = str_replace('ё', 'е', $s);
        $tokens = preg_split('/[^\p{L}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (! $tokens) return '';

        // Фамилия — первое полное слово (не инициал и не отчество): «Иванов Иван Иванович», «И.И. Иванов»
        $surnameIdx = null;
        foreach ($tokens as $i => $t) {
            if (mb_strlen($t) >= 3 && ! preg_match('/(вич|вна|чна|оглы|кызы)$/u', $t)) { $surnameIdx = $i; break; }
        }
        if ($surnameIdx === null) {   // только инициалы и отчество — берём самое длинное слово
            $surnameIdx = 0;
            foreach ($tokens as $i => $t) {
                if (mb_strlen($t) > mb_strlen($tokens[$surnameIdx])) $surnameIdx = $i;
            }
        }
        $surname = $tokens[$surnameIdx];
        if (mb_strlen($surname) < 3 || in_array($surname, ['нет', 'без', 'никто'], true)) return '';

        $initials = '';
        foreach ($tokens as $i => $t) {
            if ($i === $surnameIdx) continue;
            $initials .= mb_strlen($t) <= 2 ? $t : mb_substr($t, 0, 1);   // «ИИ» → «ии», «Иван» → «и»
        }

        return trim($surname . ' ' . mb_substr($initials, 0, 2));
    }

    /** Несколько исполнителей в одном поле: «Иванов И.И., Петров П.П.» / «… и …». */
    public static function split(string $value): array
    {
        $parts = preg_split('/[,;\/\n\r]+|\s+и\s+/u', $value) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn ($p) => $p !== ''));
    }

    /**
     * Исполнитель для написания: ключ сотрудника из справочника, а если его нет — ключ написания.
     * null — значение не похоже на ФИО.
     */
    public function id(string $raw): ?string
    {
        $key = self::key($raw);
        if ($key === '') return null;

        $id = $this->matchDirectory($key);
        $clean = trim(preg_replace('/\s+/u', ' ', $raw));
        $this->seen[$id][$clean] = ($this->seen[$id][$clean] ?? 0) + 1;

        return $id;
    }

    /** Имя для показа: из справочника, иначе самое частое написание. */
    public function label(string $id): string
    {
        if (isset($this->directory[$id])) return $this->directory[$id];
        $variants = $this->seen[$id] ?? [];
        arsort($variants);

        return (string) (array_key_first($variants) ?? $id);
    }

    /** Есть ли исполнитель в справочнике сотрудников. */
    public function known(string $id): bool
    {
        return isset($this->directory[$id]);
    }

    /** Как ещё писали этого исполнителя (кроме имени для показа). */
    public function variants(string $id): array
    {
        $label = $this->label($id);

        return array_values(array_filter(array_keys($this->seen[$id] ?? []), fn ($v) => $v !== $label));
    }

    private function matchDirectory(string $key): string
    {
        if (isset($this->directory[$key])) return $key;

        // «Иванов И.» или «Иванов» — к единственному сотруднику с такой фамилией и подходящими инициалами
        [$surname, $initials] = array_pad(explode(' ', $key, 2), 2, '');
        if (mb_strlen($initials) < 2) {
            $candidates = array_filter(array_keys($this->directory), function ($k) use ($surname, $initials) {
                [$s, $i] = array_pad(explode(' ', $k, 2), 2, '');
                return $s === $surname && ($initials === '' || str_starts_with($i, $initials));
            });
            if (count($candidates) === 1) return reset($candidates);
        }

        return $key;
    }

    /** Справочник: ключ => краткое ФИО (Иванов И.И.). Если у двух сотрудников ключ совпал — первый. */
    private static function loadDirectory(): array
    {
        $out = [];
        foreach (Employee::query()->orderBy('id')->get(['short_name', 'last_name', 'first_name', 'middle_name']) as $e) {
            $name = trim((string) $e->short_name) ?: trim("{$e->last_name} {$e->first_name} {$e->middle_name}");
            $key = self::key($name);
            if ($key !== '' && ! isset($out[$key])) $out[$key] = $name;
        }

        return $out;
    }
}
