<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PgpRecord extends Model
{
    protected $fillable = [
        'record_date', 'post_number', 'peripheral_number',
        'worker1', 'worker2', 'worker3', 'worker4', 'worker5',
        'work_start', 'work_end',
        'board_number', 'ip_address', 'fuselage_number',
        'frame_serial',

        'cross3_number', 'cross3_worker', 'cross3_record_date', 'cross3_health', 'cross3_status_text',
        'bracket_serial', 'bracket_worker', 'bracket_record_date', 'bracket_health', 'bracket_status_text',
        'manet_number', 'manet_worker', 'manet_record_date', 'manet_health', 'manet_status_text',

        'status', 'defect_reason', 'actions_taken',
        'created_by', 'updated_by', 'fault_code',
    ];

    protected function casts(): array
    {
        return [
            'record_date'          => 'date',
            'work_start'           => 'datetime:H:i',
            'work_end'             => 'datetime:H:i',
            'post_number'          => 'integer',
            'peripheral_number'    => 'integer',
            'cross3_record_date'   => 'date',
            'bracket_record_date'  => 'date',
            'manet_record_date'    => 'date',
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