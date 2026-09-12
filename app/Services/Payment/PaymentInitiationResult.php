<?php

namespace App\Services\Payment;

use App\Models\Payment;

/**
 * Safe, client-facing summary of a payment creation/initiation attempt.
 * Never carries provider secrets.
 */
final readonly class PaymentInitiationResult
{
    public function __construct(
        public Payment $payment,
        public ?string $gatewayName = null,
        public ?string $paymentUrl = null,
        public ?string $transactionReference = null,
        /** @var array<string, mixed>|null */
        public ?array $qrInfo = null,
    ) {}
}
