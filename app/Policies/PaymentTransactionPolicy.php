<?php

namespace App\Policies;

use App\Models\Payment;
use App\Models\PaymentTransaction;
use App\Models\User;

class PaymentTransactionPolicy
{
    public function view(User $user, PaymentTransaction $paymentTransaction): bool
    {
        if ($paymentTransaction->payment->payer_id === $user->id) {
            return true;
        }

        return $user->hasPermission('payment.view')
            && $user->isOrganizationAdmin($paymentTransaction->payment->paymentSchedule->classModel->organization_id);
    }

    public function create(User $user, Payment $payment): bool
    {
        return $user->hasPermission('payment.update')
            && $user->isOrganizationAdmin($payment->paymentSchedule->classModel->organization_id);
    }
}
