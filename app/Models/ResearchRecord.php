<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResearchRecord extends Model
{
    /** Статус исследования: подпись и цвет (тон ui-pill). «Отправлен в конвейер» = отремонтировано. */
    public const STATUSES = [
        'new'      => ['label' => 'Не обработан',         'tone' => 'bad'],
        'in_work'  => ['label' => 'В работе',             'tone' => 'neutral'],
        'analysis' => ['label' => 'Отправлен в разбор',   'tone' => 'warn'],
        'conveyor' => ['label' => 'Отправлен в конвейер', 'tone' => 'good'],
    ];

    /** [ключ => подпись] — для выпадающего списка в форме. */
    public static function statusOptions(): array
    {
        return array_map(fn ($s) => $s['label'], self::STATUSES);
    }

    protected static function booted(): void
    {
        // «Отремонтировано» не вводится отдельно — следует из статуса (по нему работают ремонтник и ПГП)
        static::saving(function (self $r) {
            if (! isset(self::STATUSES[$r->repair_status ?? ''])) $r->repair_status = $r->is_repaired ? 'conveyor' : 'new';
            $r->is_repaired = $r->repair_status === 'conveyor';
        });
    }

    protected $fillable = [
        'record_date', 'seq', 'category', 'repair_status',
        'worker1', 'worker2', 'worker3', 'worker4', 'worker5',
        'fuselage_no', 'fuselage_serial', 'frame_serial', 'bracket_serial',
        'reported_defect', 'rejected_by', 'analysis_actions', 'found_defect',
        'result', 'replacement', 'is_repaired', 'fault_code', 'created_by', 'updated_by',
    ];
    // pgp_record_id — только явно (forceFill): ставится при автосоздании по браку ПГП

    protected $casts = [
        'record_date' => 'date',
        'is_repaired' => 'boolean',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Запись ПГП, по браку которой заведено исследование. */
    public function pgpRecord()
    {
        return $this->belongsTo(PgpRecord::class, 'pgp_record_id');
    }

    public function comments()
    {
        return $this->hasMany(ResearchComment::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * Все исследования той же детали (включая это): совпадает № фюзеляжа, моторамы или кронштейна.
     * Деталь может возвращаться несколько раз — история собирается по всем её исследованиям.
     */
    public function samePartHistory()
    {
        $keys = array_filter([
            'fuselage_no'    => trim((string) $this->fuselage_no),
            'frame_serial'   => trim((string) $this->frame_serial),
            'bracket_serial' => trim((string) $this->bracket_serial),
        ], fn ($v) => $v !== '');

        $query = static::query()->with(['comments.user']);
        if (! $keys) return $query->whereKey($this->id)->get();

        return $query->where(function ($w) use ($keys) {
            foreach ($keys as $col => $val) $w->orWhere($col, $val);
        })->orderBy('record_date')->orderBy('id')->get();
    }
}