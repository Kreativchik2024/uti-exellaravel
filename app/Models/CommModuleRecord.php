<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class CommModuleRecord extends Model
{
    protected $fillable = [
        'record_date','serial_number','ip_address',
        'worker1','worker2','worker3','worker4','worker5',
        'notes','created_by','updated_by','fault_code',
    ];
    protected function casts(): array { return ['record_date'=>'date']; }
    public function creator() { return $this->belongsTo(User::class,'created_by'); }
}