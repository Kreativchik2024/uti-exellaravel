<?php

namespace App\Policies;

use App\Models\User;
use App\Models\FirmwareVersion;

class FirmwarePolicy
{
    public function viewAny(User $u): bool { return true; }
    public function view(User $u, FirmwareVersion $f): bool { return true; }
    public function download(User $u, FirmwareVersion $f): bool { return true; }
    public function create(User $u): bool { return $u->canManageContent(); }
    public function update(User $u, FirmwareVersion $f): bool { return $u->canManageContent(); }
    public function delete(User $u, FirmwareVersion $f): bool { return $u->canManageContent(); }
    public function manageCategories(User $u): bool { return $u->isAdmin(); }
}