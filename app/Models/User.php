<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    // role, is_active, pin_hash, session_id — только явно (forceFill), чтобы их нельзя было
    // подставить через данные формы, если где-то по ошибке передадут $request->all()
    protected $fillable = [
        'name', 'email', 'password', 'last_login_at',
    ];

    protected $hidden = [
        'password', 'remember_token', 'pin_hash', 'session_id',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'consent_at'        => 'datetime',
        ];
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    /** Участки, назначенные пользователю (админ видит все — см. SectionContext). */
    public function sections()
    {
        return $this->belongsToMany(Section::class);
    }

    /** Роли и подписи. Роль назначается в карточке сотрудника (App\Models\Employee), у учётной записи — копия. */
    public const ROLES = [
        'admin'    => 'Администратор',
        'master'   => 'Мастер',
        'executor' => 'Исполнитель',
        'otk'      => 'ОТК',
        'repair'   => 'Ремонтник',
    ];

    public function isAdmin(): bool    { return $this->role === 'admin'; }
    public function isMaster(): bool   { return $this->role === 'master'; }
    public function isOtk(): bool      { return $this->role === 'otk'; }
    public function isRepair(): bool   { return $this->role === 'repair'; }
    /** Исполнитель, ОТК и ремонтник — рабочие сотрудники участка (пока с одинаковыми правами). */
    public function isWorker(): bool   { return in_array($this->role, ['executor', 'otk', 'repair'], true); }

    public function canManageContent(): bool { return $this->isAdmin() || $this->isMaster(); }
    public function canManageUsers(): bool   { return $this->isAdmin(); }
    public function canViewFullStats(): bool { return $this->isAdmin() || $this->isMaster(); }

    public function hasPin(): bool
    {
        return !empty($this->pin_hash);
    }

    public function setPin(string $pin): void
    {
        $this->forceFill(['pin_hash' => Hash::make($pin)])->saveQuietly();
    }

    public function checkPin(string $pin): bool
    {
        if (!$this->pin_hash) return false;
        return Hash::check($pin, $this->pin_hash);
    }
}