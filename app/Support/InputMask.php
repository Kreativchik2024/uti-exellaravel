<?php

namespace App\Support;

/**
 * Маска ввода поля: 9 — цифра, A — буква (латиница или кириллица), * — буква или цифра,
 * \X — символ X как есть; остальные символы — постоянные (подставляются сами).
 * Пример: «УМН-9999», «AA-999999», «\9-99».
 * Тот же разбор — в resources/js/input-mask.js (подсказка при вводе).
 */
class InputMask
{
    /** @return list<array{t: string, ch: string}> t: 9 | A | * | lit */
    public static function tokens(string $pattern): array
    {
        $out = [];
        $chars = mb_str_split($pattern);
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];
            if ($c === '\\' && $i + 1 < $n) { $out[] = ['t' => 'lit', 'ch' => $chars[++$i]]; continue; }
            $out[] = in_array($c, ['9', 'A', '*'], true) ? ['t' => $c, 'ch' => $c] : ['t' => 'lit', 'ch' => $c];
        }

        return $out;
    }

    /** Проверка значения целиком; постоянные символы — без учёта регистра. */
    public static function regex(string $pattern): string
    {
        $re = '';
        foreach (self::tokens($pattern) as $t) {
            $re .= match ($t['t']) {
                '9'     => '\d',
                'A'     => '\p{L}',
                '*'     => '[\p{L}\d]',
                default => preg_quote($t['ch'], '/'),
            };
        }

        return '/^' . $re . '$/iu';
    }

    public static function matches(string $pattern, string $value): bool
    {
        return (bool) preg_match(self::regex($pattern), $value);
    }

    /** Как выглядит маска для человека: «УМН-0000», 0 — цифра, Я — буква, ? — буква или цифра. */
    public static function placeholder(string $pattern): string
    {
        return implode('', array_map(fn ($t) => match ($t['t']) { '9' => '0', 'A' => 'Я', '*' => '?', default => $t['ch'] }, self::tokens($pattern)));
    }

    /** Расшифровка значков, которые есть в маске. */
    public static function legend(array $patterns): string
    {
        $used = [];
        foreach ($patterns as $p) foreach (self::tokens($p) as $t) $used[$t['t']] = true;
        $parts = array_filter([
            isset($used['9']) ? '0 — цифра' : null,
            isset($used['A']) ? 'Я — буква' : null,
            isset($used['*']) ? '? — буква или цифра' : null,
        ]);

        return implode(', ', $parts);
    }

    /** Ошибка в маске (для формы администратора) или null. */
    public static function error(string $pattern): ?string
    {
        if (trim($pattern) === '') return 'Маска пустая.';
        $tokens = self::tokens($pattern);
        if (! array_filter($tokens, fn ($t) => $t['t'] !== 'lit')) return 'В маске нет ни одного места для ввода: добавьте 9, A или *.';

        return null;
    }
}
