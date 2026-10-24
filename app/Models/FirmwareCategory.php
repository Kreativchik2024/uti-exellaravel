<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FirmwareCategory extends Model
{
    protected $fillable = ['slug', 'title', 'is_system', 'sort_order'];

    protected function casts(): array
    {
        return ['is_system' => 'boolean'];
    }

    public function versions()
    {
        return $this->hasMany(FirmwareVersion::class, 'category_id');
    }
}