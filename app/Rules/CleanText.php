<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * «Чистый текст» для чата и имён. Не пропускает:
 *  - «залго» — буквы с горой надстрочных/подстрочных знаков (в͍̙̠̠͈с̸̝̥ем);
 *  - рисунки из символов Брайля и псевдографики (⣿⣿⠿, █▓░, ╔═╗);
 *  - невидимые символы и смену направления текста (ими подделывают имена и прячут текст).
 *
 * Обычные буквы с ударением/точками (ё, й, é) проходят: у них один знак на букву.
 * Тот же набор есть в чате (chat-widget.blade.php, stripEmoji) — менять вместе.
 */
class CleanText implements ValidationRule
{
    /** 2+ комбинируемых знака подряд — «залго» (ё/й в разложенном виде — один знак). */
    private const ZALGO = '/\p{M}{2,}/u';

    /** Шрифт Брайля, псевдографика (рамки, блоки, геометрические фигуры). */
    private const ART = '/[\x{2800}-\x{28FF}\x{2500}-\x{25FF}]/u';

    /** Невидимые символы и управление направлением текста. */
    private const INVISIBLE = '/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}-\x{206F}\x{FEFF}\x{00AD}\x{180E}]/u';

    public static function problem(string $value): ?string
    {
        return match (true) {
            (bool) preg_match(self::ZALGO, $value)     => 'Текст с «наслоенными» символами (залго) не разрешён.',
            (bool) preg_match(self::ART, $value)       => 'Рисунки из символов (Брайль, псевдографика) не разрешены.',
            (bool) preg_match(self::INVISIBLE, $value) => 'Текст содержит невидимые символы.',
            default => null,
        };
    }

    /** Очистка для показа уже сохранённого текста: лишние знаки, рисунки и невидимые символы убираются. */
    public static function strip(string $value): string
    {
        $value = preg_replace('/(\p{M})\p{M}+/u', '$1', $value) ?? $value;
        $value = preg_replace([self::ART, self::INVISIBLE], '', $value) ?? $value;

        return trim(preg_replace('/[ \t]{2,}/u', ' ', $value) ?? $value);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && $value !== '' && ($problem = self::problem($value))) {
            $fail($problem);
        }
    }
}
