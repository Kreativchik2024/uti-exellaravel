<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PgpRecord extends Model
{
    protected $fillable = [
        'record_date',
        'post_number',
        'peripheral_number',
        'worker1', 'worker2', 'worker3', 'worker4', 'worker5',
        'work_start', 'work_end',
        'board_number', 'ip_address', 'fuselage_number',
        'frame_serial',    // <-- ДОБАВЛЕНО
        'bracket_serial',  // <-- ДОБАВЛЕНО
        'status', 'defect_reason', 'actions_taken',
        'created_by', 'updated_by',
        'fault_code',
    ];

    protected function casts(): array
    {
        return [
            'record_date'       => 'date',
            'work_start'        => 'datetime:H:i',
            'work_end'          => 'datetime:H:i',
            'post_number'       => 'integer',
            'peripheral_number' => 'integer',
        ];
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