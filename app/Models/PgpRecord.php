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
        'created_by', 'updated_by', 'fault_code', 'after_research_id',
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
     * Цепочка документов борта: эта запись «Не сдан» → исследование (ремонт) → повторная проверка —
     * НОВАЯ запись ПГП с after_research_id = исследование → «Сдан» или снова в ремонт.
     */
    public function afterResearch()
    {
        return $this->belongsTo(ResearchRecord::class, 'after_research_id');
    }

    /** Повторная проверка после ремонта (новая запись ПГП, связанная с исследованием). */
    public function isRecheck(): bool
    {
        return $this->after_research_id !== null;
    }

    /** Ремонт по этой записи, отправленный на испытания и ещё без повторной проверки, — или null. */
    public function pendingRecheckResearch(): ?ResearchRecord
    {
        if ($this->status === 'Сдан' || ! $this->exists) return null;

        return $this->researches()->where('repair_status', 'conveyor')->whereDoesntHave('recheck')->orderByDesc('id')->first();
    }

    /** Борт вернулся на ПГП после ремонта и ждёт повторной проверки (новой записью — см. pendingRecheckResearch). */
    public function returnedFromRepair(): bool
    {
        return $this->pendingRecheckResearch() !== null;
    }

    public function updater()
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}