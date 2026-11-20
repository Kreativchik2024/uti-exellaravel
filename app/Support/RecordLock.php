<?php

namespace App\Support;

/**
 * Суточная блокировка записи учёта: через 24 ч после создания исполнитель её уже не правит.
 * Не действует для мастера и админа. Борт после ремонта проверяют новой записью — старая не правится.
 */
class RecordLock
{
    public static function isLocked($record): bool
    {
        if (! $record || ! $record->created_at) return false;
        if (auth()->user()?->canManageContent()) return false;

        return $record->created_at->copy()->addHours(24)->isPast();
    }
}
