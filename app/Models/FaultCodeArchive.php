<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Код из стёртого справочника: ждёт привязки к новому коду при следующей загрузке. */
class FaultCodeArchive extends Model
{
    protected $table = 'fault_code_archive';

    protected $fillable = ['code', 'description', 'hint', 'group_label'];
}
