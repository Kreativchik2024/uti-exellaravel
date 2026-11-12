<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * «Сотрудник сейчас работает здесь»: отметка сессии браузера (на ней держатся
 * «занят» в формах и «в сети» в чате). Снимается при выходе из аккаунта —
 * иначе сотрудника нельзя было бы сразу поставить на другое рабочее место.
 */
class ActiveWorkerSession extends Model
{
    protected $fillable = [
        'employee_id', 'user_id', 'session_id',
        'sheet', 'record_id', 'last_heartbeat_at',
    ];

    protected $casts = [
        'last_heartbeat_at' => 'datetime',
    ];

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Выход из аккаунта: снять все отметки этой сессии и освободить сотрудника,
     * привязанного к аккаунту, на всех рабочих местах.
     */
    public static function releaseOnLogout(?User $user, ?string $sessionId): void
    {
        if ($sessionId) {
            static::where('session_id', $sessionId)->delete();
        }
        if ($user && $user->employee) {
            static::where('employee_id', $user->employee->id)->delete();
        }
    }

    /** Освободить сотрудника на всех рабочих местах, кроме текущей сессии (он пересел сюда). */
    public static function releaseEmployeeElsewhere(int $employeeId, string $sessionId): void
    {
        static::where('employee_id', $employeeId)->where('session_id', '!=', $sessionId)->delete();
    }
}
