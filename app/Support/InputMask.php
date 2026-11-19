<?php

namespace App\Support;

/**
 * Маска ввода поля: цифра — 0, 9 или #; буква — Я, А (рус.) или A (лат.); буква или цифра — ?, _ или *;
 * \X — символ X как есть (например \0 — сам ноль); остальные символы — постоянные (подставляются сами).
 * Пример: «УМН-0000», «ЯЯ-000000», «\0-00».
 * Тот же разбор — в resources/js/input-mask.js (подсказка при вводе).
 */
class InputMask
{
    /**
     * Значки места для ввода — с запасом, чтобы маску можно было набрать «как видишь»:
     * цифра — 9, 0 или #; буква — A (лат.), А (рус.) или Я; буква или цифра — *, ? или _.
     */
    public const DIGIT  = ['9', '0', '#'];
    public const LETTER = ['A', 'А', 'Я'];
    public const ANY    = ['*', '?', '_'];

    /** @return list<array{t: string, ch: string}> t: 9 | A | * | lit */
    public static function tokens(string $pattern): array
    {
        $out = [];
        $chars = mb_str_split($pattern);
        for ($i = 0, $n = count($chars); $i < $n; $i++) {
            $c = $chars[$i];
            if ($c === '\\' && $i + 1 < $n) { $out[] = ['t' => 'lit', 'ch' => $chars[++$i]]; continue; }
            $out[] = match (true) {
                in_array($c, self::DIGIT, true)  => ['t' => '9', 'ch' => $c],
                in_array($c, self::LETTER, true) => ['t' => 'A', 'ch' => $c],
                in_array($c, self::ANY, true)    => ['t' => '*', 'ch' => $c],
                default                          => ['t' => 'lit', 'ch' => $c],
            };
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
        if (! array_filter($tokens, fn ($t) => $t['t'] !== 'lit')) {
            return 'В маске нет места для ввода: обозначьте цифру знаком 0 (или 9), букву — Я (или А), букву или цифру — ? (или _). Например: УМН-0000.';
        }

        return null;
    }
}
