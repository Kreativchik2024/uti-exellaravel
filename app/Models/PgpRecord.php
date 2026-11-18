<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PgpRecord extends Model
{
    protected $fillable = [
        'record_date', 'post_number', 'peripheral_number',
        'worker1', 'worker2', 'worker3', 'worker4', 'worker5',
        'work_start', 'work_end',
        'board_number', 'board_type', 'ip_address', 'fuselage_number',
        'frame_serial',

        'cross3_number', 'cross3_worker', 'cross3_record_date', 'cross3_health', 'cross3_status_text',
        'bracket_serial', 'bracket_worker', 'bracket_record_date', 'bracket_health', 'bracket_status_text',
        'manet_number', 'manet_worker', 'manet_record_date', 'manet_health', 'manet_status_text',

        'status', 'technologist', 'wait_minutes', 'defect_reason', 'actions_taken',
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
            'tech_processed'       => 'boolean',
            'wait_minutes'         => 'integer',
            'tech_updated_at'      => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // Тип борта не указан — определяем по номеру («УМН-12» → УМН), см. App\Support\BoardTypes
        static::saving(function (self $record) {
            // Номер борта — к общему виду «RA-12345», чтобы потом удобно искать
            if ($record->isDirty('board_number')) {
                $record->board_number = \App\Support\BoardNumber::normalize($record->board_number);
            }
            if (blank($record->board_type) && filled($record->board_number)) {
                $record->board_type = \App\Support\BoardTypes::detect($record->board_number);
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Исследования, заведённые по браку этой записи. */
    public function researches()
    {
        return $this->hasMany(ResearchRecord::class, 'pgp_record_id');
    }

    /**
     * Борт вернулся на ПГП после ремонта: запись ещё «не сдан», а исследование по ней отправлено в конвейер.
     * Такую запись перепроверяет и правит любой исполнитель (не только тот, кто её создал, и без ограничения 24 ч):
     * после проверки ставит «Сдан» в этой же записи — борт в статистике считается один раз, как сданный.
     */
    public function returnedFromRepair(): bool
    {
        if ($this->status === 'Сдан' || ! $this->exists) return false;

        return $this->researches()->where('repair_status', 'conveyor')->exists();
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}