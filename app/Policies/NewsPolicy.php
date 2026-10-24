<?php

namespace App\Policies;

use App\Models\News;
use App\Models\User;

class NewsPolicy
{
    public function viewAny(User $u): bool { return true; }
    public function view(User $u, News $n): bool { return true; }
    public function create(User $u): bool { return $u->canManageContent(); }
    public function update(User $u, News $n): bool { return $u->canManageContent(); }
    public function delete(User $u, News $n): bool { return $u->canManageContent(); }
}