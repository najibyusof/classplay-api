<?php

namespace App\Policies;

use App\Models\ClassModel;
use App\Models\Organization;
use App\Models\User;

class ClassModelPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, ClassModel $classModel): bool
    {
        if ($user->hasPermission('class.view') && $user->isOrganizationAdmin($classModel->organization_id)) {
            return true;
        }

        return $classModel->participants()->where('user_id', $user->id)->exists();
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission('class.create') && $user->isOrganizationAdmin($organization->id);
    }

    public function update(User $user, ClassModel $classModel): bool
    {
        return $user->hasPermission('class.update') && $user->isOrganizationAdmin($classModel->organization_id);
    }

    public function delete(User $user, ClassModel $classModel): bool
    {
        return $user->hasPermission('class.delete') && $user->isOrganizationAdmin($classModel->organization_id);
    }
}
