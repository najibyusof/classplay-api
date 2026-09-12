<?php

namespace App\Policies;

use App\Models\ClassModel;
use App\Models\ClassPaymentSetting;
use App\Models\User;

class ClassPaymentSettingPolicy
{
    public function view(User $user, ClassPaymentSetting $classPaymentSetting): bool
    {
        if ($user->hasPermission('class.view') && $user->isOrganizationAdmin($classPaymentSetting->classModel->organization_id)) {
            return true;
        }

        return $classPaymentSetting->classModel->participants()->where('user_id', $user->id)->exists();
    }

    public function create(User $user, ClassModel $classModel): bool
    {
        return $user->hasPermission('class.update') && $user->isOrganizationAdmin($classModel->organization_id);
    }

    public function update(User $user, ClassPaymentSetting $classPaymentSetting): bool
    {
        return $user->hasPermission('class.update') && $user->isOrganizationAdmin($classPaymentSetting->classModel->organization_id);
    }

    public function delete(User $user, ClassPaymentSetting $classPaymentSetting): bool
    {
        return $user->hasPermission('class.update') && $user->isOrganizationAdmin($classPaymentSetting->classModel->organization_id);
    }
}
