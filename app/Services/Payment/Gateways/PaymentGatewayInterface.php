<?php

namespace App\Services\Payment\Gateways;

use App\Models\Payment;

interface PaymentGatewayInterface
{
    /**
     * Machine-readable gateway identifier, stored on payment_transactions.gateway_name.
     */
    public function name(): string;

    /**
     * Initiate a merchant payment for the given (already persisted) Payment.
     * The gateway must use $payment->total_amount as the authoritative amount —
     * it must never accept an amount from the caller.
     */
    public function initiatePayment(Payment $payment): GatewayInitiationResult;
}
