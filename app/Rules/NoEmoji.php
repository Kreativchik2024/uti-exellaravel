<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class NoEmoji implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match('/\p{Emoji}/u', $value)) {
            $fail('Поле :attribute не должно содержать эмодзи.');
        }
    }
}