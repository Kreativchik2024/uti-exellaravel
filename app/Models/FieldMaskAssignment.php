<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Маска у поля вкладки: sheet + field → маска. У поля может быть несколько масок — подходит любая. */
class FieldMaskAssignment extends Model
{
    protected $fillable = ['sheet', 'field', 'field_mask_id', 'position'];

    public function mask()
    {
        return $this->belongsTo(FieldMask::class, 'field_mask_id');
    }
}
