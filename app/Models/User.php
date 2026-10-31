<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name', 'email', 'password', 'pin_hash', 'role',
        'is_active', 'session_id', 'last_login_at',
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
        ];
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function isAdmin(): bool    { return $this->role === 'admin'; }
    public function isMaster(): bool   { return $this->role === 'master'; }
    public function isExecutor(): bool { return $this->role === 'executor'; }

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