<?php

namespace App\Policies;

use App\Models\Permission;
use App\Models\User;

class PermissionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('ADMIN');
    }

    public function view(User $user, Permission $permission): bool
    {
        return $user->hasRole('ADMIN');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('ADMIN');
    }

    public function update(User $user, Permission $permission): bool
    {
        return $user->hasRole('ADMIN');
    }

    public function delete(User $user, Permission $permission): bool
    {
        return $user->hasRole('ADMIN');
    }
}
