<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Запрет эмодзи в именах (ФИО, ник в чате).
 *
 * Явные диапазоны, а не \p{Emoji}: это свойство Юникода включает цифры 0–9, «#» и «*»,
 * из-за чего отклонялись обычные имена вроде «Иванов 2». Диапазоны покрывают
 * пиктограммы, флаги, символы-«значки» (☀ ✅ ⭐), модификаторы цвета кожи,
 * а также служебные знаки, из которых собираются составные эмодзи (FE0F, ZWJ, 20E3).
 */
class NoEmoji implements ValidationRule
{
    // Тот же набор продублирован в чате (chat-widget.blade.php, stripEmoji) — менять вместе
    private const PATTERN = '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{2300}-\x{23FF}'
        . '\x{3030}\x{303D}\x{3297}\x{3299}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u';

    public static function has(string $value): bool
    {
        return (bool) preg_match(self::PATTERN, $value);
    }

    /** Убирает эмодзи и схлопывает лишние пробелы (для отображения старых имён и импорта). */
    public static function strip(string $value): string
    {
        $clean = preg_replace(self::PATTERN, '', $value) ?? $value;

        return trim(preg_replace('/\s{2,}/u', ' ', $clean) ?? $clean);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $value !== '' && self::has($value)) {
            $fail('Смайлы (эмодзи) здесь не разрешены.');
        }
    }
}
