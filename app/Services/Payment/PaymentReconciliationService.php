<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Models\PaymentTransaction;

/**
 * Extension point for future automated and manual payment reconciliation.
 *
 * Future implementations will compare provider settlement reports / gateway transaction records
 * against internal ClassPay Payment and PaymentTransaction records to detect discrepancies.
 */
class PaymentReconciliationService
{
    /**
     * Reconcile a single transaction against gateway status.
     *
     * @return array{matched: bool, discrepancy_type: string|null, details: array<string, mixed>}
     */
    public function reconcileTransaction(PaymentTransaction $transaction): array
    {
        $payment = $transaction->payment;

        if (! $payment) {
            return [
                'matched' => false,
                'discrepancy_type' => 'missing_payment',
                'details' => ['transaction_id' => $transaction->id],
            ];
        }

        $matched = ($payment->status === 'paid' && $transaction->response_status === 'paid')
            || ($payment->status === 'failed' && $transaction->response_status === 'failed');

        return [
            'matched' => $matched,
            'discrepancy_type' => $matched ? null : 'status_mismatch',
            'details' => [
                'payment_id' => $payment->id,
                'payment_status' => $payment->status,
                'transaction_id' => $transaction->id,
                'transaction_status' => $transaction->response_status,
                'expected_amount' => (float) $payment->total_amount,
                'requested_amount' => (float) $transaction->request_amount,
            ],
        ];
    }

    /**
     * Reconcile all transactions for a given payment schedule.
     *
     * @return array{total_paid: float, required_amount: float, is_reconciled: bool}
     */
    public function reconcileSchedule(Payment $payment): array
    {
        $schedule = $payment->paymentSchedule;
        $totalPaid = (float) $schedule->payments()->where('status', 'paid')->sum('total_amount');
        $requiredAmount = (float) $schedule->required_amount;

        return [
            'total_paid' => $totalPaid,
            'required_amount' => $requiredAmount,
            'is_reconciled' => $schedule->status === 'paid' ? ($totalPaid >= $requiredAmount) : true,
        ];
    }
}
