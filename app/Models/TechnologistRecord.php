<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class TechnologistRecord extends Model
{
    protected $fillable = [
        'record_date','act_number','node_name','serial_number','rejected_by',
        'defect_description','defect_node','conclusion','technologist',
        'from_section','to_section','sent_to_repair_at','repair_status',
        'planned_ready_at','responsible','actual_return_at','notes',
        'worker1','worker2','worker3','worker4','worker5',
        'created_by','updated_by','fault_code',
    ];
    protected function casts(): array {
        return [
            'record_date'=>'date',
            'sent_to_repair_at'=>'date',
            'planned_ready_at'=>'date',
            'actual_return_at'=>'date',
        ];
    }
    public function creator() { return $this->belongsTo(User::class,'created_by'); }
}