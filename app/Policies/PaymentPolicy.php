<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\User;

class PaymentPolicy
{
    public function view(User $user, Payment $payment): bool
    {
        if ($payment->payer_id === $user->id) {
            return true;
        }

        $studentId = $payment->paymentSchedule->classParticipant->user_id;

        if ($studentId === $user->id || $user->isSponsorOf($studentId)) {
            return true;
        }

        return $user->hasPermission('payment.view')
            && $user->isOrganizationAdmin($payment->paymentSchedule->classModel->organization_id);
    }

    public function create(User $user, PaymentSchedule $paymentSchedule): bool
    {
        if (! $user->hasPermission('payment.create')) {
            return false;
        }

        $studentId = $paymentSchedule->classParticipant->user_id;

        return $studentId === $user->id || $user->isSponsorOf($studentId);
    }

    public function update(User $user, Payment $payment): bool
    {
        return ($user->hasPermission('payment.update') || $user->hasPermission('payment.verify'))
            && $user->isOrganizationAdmin($payment->paymentSchedule->classModel->organization_id);
    }

    public function delete(User $user, Payment $payment): bool
    {
        return $user->hasPermission('payment.update')
            && $user->isOrganizationAdmin($payment->paymentSchedule->classModel->organization_id);
    }
}
