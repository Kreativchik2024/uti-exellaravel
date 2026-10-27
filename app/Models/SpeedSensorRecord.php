<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SpeedSensorRecord extends Model
{
    protected $fillable = [
        'record_date', 'serial_number', 'is_working', 'runs_count',
        'worker1', 'worker2', 'worker3', 'worker4', 'worker5',
        'notes', 'fault_code', 'created_by', 'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'record_date' => 'date',
            'is_working'  => 'string',
            'runs_count'  => 'integer',
        ];
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}