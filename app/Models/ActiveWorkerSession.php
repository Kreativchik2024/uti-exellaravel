<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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
}