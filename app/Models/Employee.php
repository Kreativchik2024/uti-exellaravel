<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class Employee extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'last_name',
        'first_name',
        'middle_name',
        'short_name',
        'position',
        'department',
        'is_active',
    ];

    // role — только явно (forceFill) в карточке сотрудника у админа: импорт и массовое заполнение её не меняют

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        // Роль не задана явно (импорт, создание без формы) — берём текущую роль учётной записи, ничего не меняя
        static::creating(function (self $e) {
            if ($e->role === null) {
                $e->role = ($e->user_id ? User::query()->whereKey($e->user_id)->value('role') : null) ?? 'executor';
            }
        });

        // Роль задаётся у сотрудника; привязанная учётная запись получает её (при смене роли или привязке)
        static::saved(function (self $e) {
            if (! $e->user_id || ! ($e->wasRecentlyCreated || $e->wasChanged('role') || $e->wasChanged('user_id'))) return;
            User::query()->whereKey($e->user_id)->where('role', '!=', $e->role ?? 'executor')
                ->update(['role' => $e->role ?? 'executor']);
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function scopeActive($q)
    {
        return $q->where('is_active', true);
    }

    /**
     * Только сотрудники, привязанные к User.
     * Безопасно, если колонки user_id ещё нет — просто не фильтрует.
     */
    public function scopeWithUser($q)
    {
        if (!Schema::hasColumn($this->getTable(), 'user_id')) {
            return $q;
        }
        return $q->whereNotNull('user_id');
    }
}