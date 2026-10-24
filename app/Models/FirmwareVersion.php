<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class FirmwareVersion extends Model
{
    protected $fillable = [
        'category_id','version','status','file_path',
        'file_size','sha256','notes','uploaded_by','made_actual_at',
    ];

    protected function casts(): array
    {
        return [
            'uploaded_at'    => 'datetime',
            'made_actual_at' => 'datetime',
        ];
    }

    public function category() { return $this->belongsTo(FirmwareCategory::class, 'category_id'); }
    public function uploader() { return $this->belongsTo(User::class, 'uploaded_by'); }

    public function scopeActual($q)     { return $q->where('status','actual'); }
    public function scopeDeprecated($q) { return $q->where('status','deprecated'); }
    public function scopeArchive($q)    { return $q->where('status','archive'); }

    public function humanSize(): string
    {
        $size = (int) $this->file_size;
        foreach (['Б','КБ','МБ','ГБ'] as $unit) {
            if ($size < 1024) return $size . ' ' . $unit;
            $size /= 1024;
        }
        return round($size, 1) . ' ТБ';
    }
}