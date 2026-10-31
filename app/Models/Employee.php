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

    protected $casts = [
        'is_active' => 'boolean',
    ];

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