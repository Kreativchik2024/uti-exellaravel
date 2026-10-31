<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Cross3Record extends Model
{
    protected $fillable = [
        'record_date', 'serial_number', 'pc_version', 'ahrs',
        'compass_version', 'notes', 'defect', 'fault_code',
        'worker1', 'worker2', 'worker3', 'worker4', 'worker5',
        'status',       // Проверен / Требует ремонта / Брак / В работе
        'is_working',   // Исправен / Неисправен  <-- ДОБАВЛЕНО
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'record_date' => 'date',
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