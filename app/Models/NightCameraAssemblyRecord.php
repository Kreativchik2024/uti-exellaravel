<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NightCameraAssemblyRecord extends Model
{
    protected $fillable = [
        'record_date', 'camera_serial', 'qty', 'flashed_with_day',
        'worker1', 'worker2', 'worker3', 'worker4', 'worker5',
        'notes', 'fault_code', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'record_date'      => 'date',
        'qty'              => 'integer',
        'flashed_with_day' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}