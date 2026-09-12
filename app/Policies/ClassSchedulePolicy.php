<?php

namespace App\Policies;

use App\Models\ClassModel;
use App\Models\ClassSchedule;
use App\Models\User;

class ClassSchedulePolicy
{
    public function view(User $user, ClassSchedule $classSchedule): bool
    {
        if ($user->hasPermission('class.view') && $user->isOrganizationAdmin($classSchedule->classModel->organization_id)) {
            return true;
        }

        return $classSchedule->classModel->participants()->where('user_id', $user->id)->exists();
    }

    public function create(User $user, ClassModel $classModel): bool
    {
        return $user->hasPermission('class.update') && $user->isOrganizationAdmin($classModel->organization_id);
    }

    public function update(User $user, ClassSchedule $classSchedule): bool
    {
        return $user->hasPermission('class.update') && $user->isOrganizationAdmin($classSchedule->classModel->organization_id);
    }

    public function delete(User $user, ClassSchedule $classSchedule): bool
    {
        return $user->hasPermission('class.update') && $user->isOrganizationAdmin($classSchedule->classModel->organization_id);
    }
}
