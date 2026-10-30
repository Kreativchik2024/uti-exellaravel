<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Data17Record  extends Model
{
    protected $fillable = [
        'record_date', 'qty',
        'worker1', 'worker2', 'worker3', 'worker4', 'worker5',
        'notes', 'created_by', 'updated_by',
    ];

    protected $casts = [
        'record_date' => 'date',
        'qty'         => 'integer',
    ];
}