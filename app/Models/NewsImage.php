<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NewsImage extends Model
{
    public $timestamps = false;

    protected $fillable = ['news_id', 'path', 'thumb_path', 'sort_order'];

    public function news()
    {
        return $this->belongsTo(News::class);
    }
}