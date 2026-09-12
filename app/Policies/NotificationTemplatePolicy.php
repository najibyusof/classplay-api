<?php

namespace App\Policies;

use App\Models\NotificationTemplate;
use App\Models\User;

class NotificationTemplatePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasRole('ADMIN');
    }

    public function view(User $user, NotificationTemplate $notificationTemplate): bool
    {
        return $user->hasRole('ADMIN');
    }

    public function create(User $user): bool
    {
        return $user->hasRole('ADMIN');
    }

    public function update(User $user, NotificationTemplate $notificationTemplate): bool
    {
        return $user->hasRole('ADMIN');
    }

    public function delete(User $user, NotificationTemplate $notificationTemplate): bool
    {
        return $user->hasRole('ADMIN');
    }
}
