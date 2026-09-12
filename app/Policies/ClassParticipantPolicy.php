<?php

namespace App\Policies;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\User;

class ClassParticipantPolicy
{
    public function view(User $user, ClassParticipant $classParticipant): bool
    {
        if ($user->hasPermission('participant.view') && $user->isOrganizationAdmin($classParticipant->classModel->organization_id)) {
            return true;
        }

        return $classParticipant->user_id === $user->id;
    }

    public function create(User $user, ClassModel $classModel): bool
    {
        return $user->hasPermission('participant.create') && $user->isOrganizationAdmin($classModel->organization_id);
    }

    public function update(User $user, ClassParticipant $classParticipant): bool
    {
        return $user->hasPermission('participant.update') && $user->isOrganizationAdmin($classParticipant->classModel->organization_id);
    }

    public function delete(User $user, ClassParticipant $classParticipant): bool
    {
        return $user->hasPermission('participant.delete') && $user->isOrganizationAdmin($classParticipant->classModel->organization_id);
    }
}
