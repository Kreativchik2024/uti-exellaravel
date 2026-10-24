<?php

namespace App\Observers;

use App\Models\FirmwareVersion;

class FirmwareVersionObserver
{
    public function saving(FirmwareVersion $v): void
    {
        if ($v->status === 'actual' && $v->isDirty('status')) {
            FirmwareVersion::where('category_id', $v->category_id)
                ->where('id', '!=', $v->id)
                ->where('status', 'actual')
                ->update(['status' => 'deprecated']);

            $v->made_actual_at = now();
        }
    }
}