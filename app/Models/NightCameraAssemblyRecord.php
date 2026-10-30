<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NightCameraAssemblyRecord extends Model
{
    protected $fillable = [
        'record_date', 'qty',
        'worker1', 'worker2', 'worker3', 'worker4', 'worker5',
        'notes', 'fault_code', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'record_date' => 'date',
        'qty'         => 'integer',
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