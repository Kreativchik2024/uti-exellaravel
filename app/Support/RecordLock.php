<?php

namespace App\Support;

use App\Models\PgpRecord;

/**
 * Суточная блокировка записи учёта: через 24 ч после создания исполнитель её уже не правит.
 * Не действует для мастера и админа и для борта, вернувшегося на ПГП после ремонта (ремонт идёт дольше суток).
 */
class RecordLock
{
    public static function isLocked($record): bool
    {
        if (! $record || ! $record->created_at) return false;
        if (auth()->user()?->canManageContent()) return false;
        if ($record instanceof PgpRecord && $record->returnedFromRepair()) return false;

        return $record->created_at->copy()->addHours(24)->isPast();
    }
}
