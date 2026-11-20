<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResearchRecord extends Model
{
    /** Статус исследования: подпись и цвет (тон ui-pill). «Отправлен на испытания» = отремонтировано (ключ conveyor). Старые подписи — LEGACY_LABELS. */
    public const STATUSES = [
        'new'      => ['label' => 'Не обработан',         'tone' => 'bad'],
        'in_work'  => ['label' => 'В работе',             'tone' => 'neutral'],
        'analysis' => ['label' => 'Отправлен в разбор',   'tone' => 'warn'],
        'conveyor' => ['label' => 'Отправлен на испытания', 'tone' => 'good'],
    ];

    /** Прежние подписи статусов (в старых записях журнала действий) → текущие. */
    public const LEGACY_LABELS = [
        'Отправлен в конвейер' => 'Отправлен на испытания',
    ];

    /** Что сделал ремонтник с узлом. При замене технологу нужен акт. */
    public const REPAIR_ACTIONS = [
        'repair'      => 'Ремонт (без использования комплектующих)',
        'replacement' => 'Замена (с использованием комплектующих)',
    ];

    /**
     * Нужен акт: в исследовании была замена, а технолог ещё не отметил «Акт оформлен».
     * Любое исследование — и заведённое по браку ПГП, и внесённое вручную.
     */
    public function needsAct(): bool
    {
        return $this->repair_action === 'replacement' && ! $this->act_done_at;
    }

    /**
     * Ждёт технолога: замена — нужен акт; ремонт, отправленный в конвейер, — нужна приёмка.
     * Отметка технолога одна (act_done_at): «Акт оформлен» или «Принято технологом».
     * Исследования без указанного ремонта/замены (старые) сюда не попадают.
     */
    public function needsTechnologist(): bool
    {
        return $this->techCheckKind() !== null && ! $this->act_done_at;
    }

    /** 'act' — замена (акт), 'accept' — ремонт отправлен в конвейер (приёмка), null — технологу не нужно. */
    public function techCheckKind(): ?string
    {
        if ($this->repair_action === 'replacement') return 'act';
        if ($this->repair_action === 'repair' && $this->repair_status === 'conveyor') return 'accept';

        return null;
    }

    /**
     * Логичный следующий статус: не обработан → в работе → (ремонт/замена указаны) отправлен на испытания;
     * из разбора — снова в работу. null — дальше по статусу ничего не нужно.
     */
    public function nextStatus(): ?string
    {
        return match ($this->repair_status ?: 'new') {
            'new'      => 'in_work',
            'in_work'  => $this->repair_action ? 'conveyor' : null,
            'analysis' => 'in_work',
            default    => null,
        };
    }

    public function repairItemModel()
    {
        return $this->belongsTo(RepairItem::class, 'repair_item_id');
    }

    /** Все пункты ремонта и замены (repair_action / repair_item — сводка по ним). */
    public function repairItems()
    {
        return $this->hasMany(ResearchRepairItem::class)->orderBy('position')->orderBy('id');
    }

    /** «Ремонт», «Замена» или «Ремонт и замена» — по пунктам; для старых записей — по сводке. */
    public function repairActionLabel(): ?string
    {
        $kinds = $this->repairItems->pluck('action')->unique();
        if ($kinds->count() > 1) return 'Ремонт и замена';
        $kind = $kinds->first() ?? $this->repair_action;

        return $kind ? (self::REPAIR_ACTIONS[$kind] ?? $kind) : null;
    }

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
        'record_date', 'seq', 'category', 'module_serial', 'repair_status',
        'worker1', 'worker2', 'worker3', 'worker4', 'worker5',
        'fuselage_no', 'fuselage_serial', 'frame_serial', 'bracket_serial',
        'reported_defect', 'rejected_by', 'analysis_actions', 'found_defect',
        'result', 'is_repaired', 'fault_code', 'created_by', 'updated_by',
    ];
    // pgp_record_id — только явно (forceFill): ставится при автосоздании по браку ПГП

    protected $casts = [
        'record_date' => 'date',
        'is_repaired' => 'boolean',
        'act_done_at' => 'datetime',
    ];
    // repair_action, repair_item, repair_group_id, repair_item_id, act_done_* — только явно (forceFill):
    // ставятся в панели «Ремонт» и кнопкой «Акт оформлен», не через общую форму

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Запись ПГП, по браку которой заведено исследование. */
    /** Повторная проверка на ПГП после этого ремонта (новая запись ПГП). */
    public function recheck()
    {
        return $this->hasOne(PgpRecord::class, 'after_research_id');
    }

    public function pgpRecord()
    {
        return $this->belongsTo(PgpRecord::class, 'pgp_record_id');
    }

    public function comments()
    {
        return $this->hasMany(ResearchComment::class)->orderBy('created_at')->orderBy('id');
    }

    /**
     * Все исследования той же детали (включая это): совпадает № фюзеляжа, моторамы, кронштейна или S/N модуля.
     * Деталь может возвращаться несколько раз — история собирается по всем её исследованиям.
     */
    public function samePartHistory()
    {
        $keys = array_filter([
            'fuselage_no'    => trim((string) $this->fuselage_no),
            'frame_serial'   => trim((string) $this->frame_serial),
            'bracket_serial' => trim((string) $this->bracket_serial),
            'module_serial'  => trim((string) $this->module_serial),
        ], fn ($v) => $v !== '');

        $query = static::query()->with(['comments.user']);
        if (! $keys) return $query->whereKey($this->id)->get();

        return $query->where(function ($w) use ($keys) {
            // без учёта регистра и пробелов по краям: « mn-1 » = «MN-1»
            foreach ($keys as $col => $val) $w->orWhereRaw("LOWER(TRIM(`$col`)) = ?", [mb_strtolower($val)]);
        })->orderBy('record_date')->orderBy('id')->get();
    }
}