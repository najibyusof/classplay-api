<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\PaymentProof;
use App\Models\User;

class PaymentProofPolicy
{
    public function view(User $user, PaymentProof $paymentProof): bool
    {
        if ($paymentProof->payment->payer_id === $user->id) {
            return true;
        }

        return $user->hasPermission('payment.view')
            && $user->isOrganizationAdmin($paymentProof->payment->paymentSchedule->classModel->organization_id);
    }

    public function create(User $user, Payment $payment): bool
    {
        return $payment->payer_id === $user->id;
    }

    public function update(User $user, PaymentProof $paymentProof): bool
    {
        return ($user->hasPermission('payment.verify') || $user->hasPermission('payment.update'))
            && $user->isOrganizationAdmin($paymentProof->payment->paymentSchedule->classModel->organization_id);
    }
}
