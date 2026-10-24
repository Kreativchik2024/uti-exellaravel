<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class ResearchRecord extends Model
{
    protected $fillable = [
        'record_date','seq',
        'worker1','worker2','worker3','worker4','worker5',
        'fuselage_no','fuselage_serial','frame_serial','bracket_serial',
        'reported_defect','rejected_by','analysis_actions','found_defect','result',
        'created_by','updated_by',
    ];
    protected function casts(): array { return ['record_date'=>'date']; }
    public function creator() { return $this->belongsTo(User::class,'created_by'); }
}