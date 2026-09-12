<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    /**
     * Gates GET /admin/students and GET /admin/sponsors.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('ADMIN') && $user->hasPermission('participant.view');
    }

    /**
     * Gates creating a new student/sponsor account. Visibility of that
     * account is separately controlled once it participates in a class.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('participant.create');
    }

    public function view(User $user, User $target): bool
    {
        return $user->hasPermission('participant.view') && $user->canManageParticipantUser($target);
    }

    public function update(User $user, User $target): bool
    {
        return $user->hasPermission('participant.update') && $user->canManageParticipantUser($target);
    }

    public function delete(User $user, User $target): bool
    {
        return $user->hasPermission('participant.delete') && $user->canManageParticipantUser($target);
    }
}
