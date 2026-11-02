<?php

namespace App\Policies;

use App\Models\User;

class RecordPolicy
{
    /**
     * Единый признак «может управлять чужими записями».
     */
    private function canManage(User $u): bool
    {
        if ($u->canManageContent()) return true;
        if (method_exists($u, 'isAdmin')  && $u->isAdmin())  return true;
        if (method_exists($u, 'isMaster') && $u->isMaster()) return true;
        return false;
    }

    public function viewAny(User $u): bool { return true; }
    public function view(User $u, $record): bool { return true; }
    public function create(User $u): bool  { return true; }

    public function update(User $u, $record): bool
    {
        // ── Вариант А (буквально «только админ и мастер»): ──
        // return $this->canManage($u);

        // ── Вариант Б (текущий: админ/мастер ИЛИ создатель): ──
        if ($this->canManage($u)) return true;
        return (int) $record->created_by === (int) $u->id;
    }

    public function delete(User $u, $record): bool
    {
        return $this->update($u, $record);
    }
}