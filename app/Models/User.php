<?php

namespace App\Models;

use App\Rules\NoEmoji;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
        'last_login_at',
        'approved_by',
        'approved_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password'          => 'hashed',
            'is_active'         => 'boolean',
            'last_login_at'     => 'datetime',
            'approved_at'       => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if (! $user->isDirty('name')) {
                return;
            }

            $validator = Validator::make(
                ['name' => $user->name],
                ['name' => ['required', 'string', 'max:255', new NoEmoji()]]
            );

            if ($validator->fails()) {
                throw new ValidationException($validator);
            }
        });
    }

    public function isAdmin(): bool    { return $this->role === 'admin'; }
    public function isMaster(): bool   { return $this->role === 'master'; }
    public function isExecutor(): bool { return $this->role === 'executor'; }

    public function canManageContent(): bool
    {
        return in_array($this->role, ['admin', 'master']);
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}