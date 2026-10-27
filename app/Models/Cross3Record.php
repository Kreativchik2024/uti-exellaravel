<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Cross3Record extends Model
{
    protected $fillable = ['record_date','serial_number','pc_version','ahrs','compass_version','worker1','worker2','worker3','worker4','worker5','notes','defect','created_by','updated_by','fault_code',];
    protected function casts(): array { return ['record_date'=>'date']; }
    public function creator() { return $this->belongsTo(User::class,'created_by'); }
}