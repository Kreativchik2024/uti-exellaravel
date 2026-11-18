<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Свой вариант узла для ремонта/замены (когда нужного нет в дереве блоков справочника кодов).
 * Предлагает ремонтник, подтверждает администратор; до подтверждения виден только в исследовании, где предложен.
 */
class RepairItem extends Model
{
    protected $fillable = ['name', 'group_id'];

    protected $casts = ['is_approved' => 'boolean', 'approved_at' => 'datetime'];

    public function group(): BelongsTo
    {
        return $this->belongsTo(FaultCodeGroup::class, 'group_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
