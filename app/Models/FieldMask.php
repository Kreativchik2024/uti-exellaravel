<?php

namespace App\Models;

use App\Support\InputMask;
use Illuminate\Database\Eloquent\Model;

/** Маска ввода из библиотеки (Админ → Маски полей); к полям вкладок привязывается через field_mask_assignments. */
class FieldMask extends Model
{
    protected $fillable = ['name', 'pattern', 'hint'];

    public function assignments()
    {
        return $this->hasMany(FieldMaskAssignment::class);
    }

    public function placeholder(): string
    {
        return InputMask::placeholder($this->pattern);
    }
}
