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
        'two_factor_secret', 'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at'     => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'consent_at'        => 'datetime',
            // Секрет и резервные коды хранятся зашифрованными (ключ APP_KEY)
            'two_factor_secret'         => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at'   => 'datetime',
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

    public function isAdmin(): bool    { return $this->role === 'admin'; }
    public function isMaster(): bool   { return $this->role === 'master'; }
    public function isExecutor(): bool { return $this->role === 'executor'; }

    public function canManageContent(): bool { return $this->isAdmin() || $this->isMaster(); }

    /** Вход с кодом из приложения включён. */
    public function hasTwoFactor(): bool { return $this->two_factor_confirmed_at !== null && filled($this->two_factor_secret); }

    /** Для роли вход с кодом обязателен (auth.two_factor_roles). */
    public function requiresTwoFactor(): bool { return in_array($this->role, (array) config('auth.two_factor_roles'), true); }
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