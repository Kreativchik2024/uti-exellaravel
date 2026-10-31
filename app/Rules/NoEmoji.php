<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NoEmoji implements ValidationRule
{
    /**
     * Unicode-диапазоны эмодзи.
     * Используем явные диапазоны, а не \p{Emoji}, потому что на некоторых сборках PCRE
     * это свойство не поддерживается и preg_match() падает с "unknown property name".
     */
    private const EMOJI_RANGES = '/[\x{1F000}-\x{1F02F}\x{1F0A0}-\x{1F0FF}\x{1F100}-\x{1F1FF}\x{1F200}-\x{1F2FF}\x{1F300}-\x{1F5FF}\x{1F600}-\x{1F64F}\x{1F680}-\x{1F6FF}\x{1F700}-\x{1F77F}\x{1F780}-\x{1F7FF}\x{1F800}-\x{1F8FF}\x{1F900}-\x{1F9FF}\x{1FA00}-\x{1FA6F}\x{1FA70}-\x{1FAFF}\x{2600}-\x{26FF}\x{2700}-\x{27BF}\x{FE00}-\x{FE0F}\x{1F1E6}-\x{1F1FF}]/u';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (!is_string($value) || $value === '') {
            return;
        }

        if ($this->hasEmoji($value)) {
            $fail('Поле :attribute не должно содержать эмодзи.');
        }
    }

    private function hasEmoji(string $value): bool
    {
        // Сначала пробуем современное свойство \p{Emoji} (PHP 7.3+, PCRE2 10.40+)
        $result = @preg_match('/\p{Emoji}/u', $value);

        if ($result !== false) {
            // Свойство поддерживается — используем его результат
            return $result === 1;
        }

        // Fallback: явные диапазоны
        return (bool) preg_match(self::EMOJI_RANGES, $value);
    }
}