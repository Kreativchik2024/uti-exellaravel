<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Пункт «Ремонт / Замена» в исследовании: что сделано (ремонт или замена) и какой узел. */
class ResearchRepairItem extends Model
{
    protected $fillable = ['position', 'action', 'label', 'group_id', 'item_id'];

    public function research()
    {
        return $this->belongsTo(ResearchRecord::class, 'research_record_id');
    }

    public function repairItem()
    {
        return $this->belongsTo(RepairItem::class, 'item_id');
    }

    public function actionLabel(): string
    {
        return ResearchRecord::REPAIR_ACTIONS[$this->action] ?? $this->action;
    }
}
