<?php

namespace App\Policies;

use App\Models\ClassModel;
use App\Models\PaymentSchedule;
use App\Models\User;

class PaymentSchedulePolicy
{
    public function view(User $user, PaymentSchedule $paymentSchedule): bool
    {
        if ($user->hasPermission('payment.view') && $user->isOrganizationAdmin($paymentSchedule->classModel->organization_id)) {
            return true;
        }

        $studentId = $paymentSchedule->classParticipant->user_id;

        return $studentId === $user->id || $user->isSponsorOf($studentId);
    }

    public function create(User $user, ClassModel $classModel): bool
    {
        return $user->hasPermission('payment.update') && $user->isOrganizationAdmin($classModel->organization_id);
    }

    public function update(User $user, PaymentSchedule $paymentSchedule): bool
    {
        return $user->hasPermission('payment.update') && $user->isOrganizationAdmin($paymentSchedule->classModel->organization_id);
    }

    public function delete(User $user, PaymentSchedule $paymentSchedule): bool
    {
        return $user->hasPermission('payment.update') && $user->isOrganizationAdmin($paymentSchedule->classModel->organization_id);
    }

    public function sendReminder(User $user, PaymentSchedule $paymentSchedule): bool
    {
        return $user->hasPermission('notification.send') && $user->isOrganizationAdmin($paymentSchedule->classModel->organization_id);
    }
}
