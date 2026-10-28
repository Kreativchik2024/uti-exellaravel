<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = [
        'last_name',
        'first_name',
        'middle_name',
        'short_name',
        'position',
        'department',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    /**
     * Собрать short_name из ФИО (если не задан явно).
     * «Иванов Иван Иванович» → «Иванов И.И.»
     */
    public static function buildShortName(string $last, string $first, ?string $middle = null): string
    {
        $short = trim($last);

        if ($first !== '') {
            $short .= ' ' . mb_strtoupper(mb_substr($first, 0, 1)) . '.';
        }
        if ($middle !== null && $middle !== '') {
            $short .= mb_strtoupper(mb_substr($middle, 0, 1)) . '.';
        }

        return $short;
    }

    /**
     * Scope: только активные.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Полное ФИО для отображения (если нужно где-то).
     */
    public function getFullNameAttribute(): string
    {
        return trim($this->last_name . ' ' . $this->first_name . ' ' . ($this->middle_name ?? ''));
    }
}