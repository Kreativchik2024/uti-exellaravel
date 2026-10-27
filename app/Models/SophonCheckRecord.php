<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class SophonCheckRecord extends Model
{
    protected $fillable = ['record_date','worker1','worker2','worker3','worker4','worker5','reported_defect','rejected_by','found_defect','work_done','stand_test','notes','status','created_by','updated_by','fault_code',];
    protected function casts(): array { return ['record_date'=>'date']; }
    public function creator() { return $this->belongsTo(User::class,'created_by'); }
}