<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserDevice;

class UserDevicePolicy
{
    public function view(User $user, UserDevice $userDevice): bool
    {
        return $userDevice->user_id === $user->id;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, UserDevice $userDevice): bool
    {
        return $userDevice->user_id === $user->id;
    }

    public function delete(User $user, UserDevice $userDevice): bool
    {
        return $userDevice->user_id === $user->id;
    }
}
