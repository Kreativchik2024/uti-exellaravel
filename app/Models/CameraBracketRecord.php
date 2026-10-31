<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CameraBracketRecord extends Model
{
    protected $fillable = [
        'record_date',
        'serial_number',
        'camera_status',
        'night_camera_status',
        'speed_sensor_status',
        'barometer_status',
        'suspension_status', // <-- ДОБАВЛЕНО
        'worker1',
        'worker2',
        'worker3',
        'worker4',
        'worker5',
        'notes',
        'comments',
        'created_by',
        'updated_by',
        'fault_code',
    ];

    protected function casts(): array
    {
        return ['record_date' => 'date'];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}