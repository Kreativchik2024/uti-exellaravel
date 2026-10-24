<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class PcCheckRecord extends Model
{
    protected $fillable = ['record_date','qty','worker1','worker2','worker3','worker4','worker5','notes','created_by','updated_by'];
    protected function casts(): array { return ['record_date'=>'date']; }
    public function creator() { return $this->belongsTo(User::class,'created_by'); }
}