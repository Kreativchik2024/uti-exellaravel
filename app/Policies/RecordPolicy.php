<?php

namespace App\Policies;

use App\Models\User;

class RecordPolicy
{
    public function viewAny(User $u): bool { return true; }
    public function view(User $u, $record): bool { return true; }
    public function create(User $u): bool  { return true; }

    public function update(User $u, $record): bool
    {
        if ($u->canManageContent()) return true;
        return (int) $record->created_by === (int) $u->id;
    }

    public function delete(User $u, $record): bool
    {
        return $this->update($u, $record);
    }
}