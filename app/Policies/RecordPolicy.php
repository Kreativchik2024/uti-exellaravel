<?php

namespace App\Policies;

use App\Models\User;

class RecordPolicy
{
    /**
     * Единый признак «может управлять чужими записями».
     * Админ, мастер или пользователь с правом canManageContent.
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
        if ($this->canManage($u)) return true;
        return (int) $record->created_by === (int) $u->id;
    }

    public function delete(User $u, $record): bool
    {
        // Удалять могут только админ и мастер.
        // Если создатель тоже должен мочь удалять, замените на:
        // return $this->update($u, $record);
        return $this->canManage($u);
    }
}