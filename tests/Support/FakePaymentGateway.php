<?php

namespace Tests\Support;

use App\Models\Payment;
use App\Services\Payment\Gateways\GatewayInitiationResult;
use App\Services\Payment\Gateways\PaymentGatewayInterface;

/**
 * Deterministic test double for PaymentGatewayInterface. Never makes a real
 * HTTP call, so tests stay fast and offline.
 */
class FakePaymentGateway implements PaymentGatewayInterface
{
    /** @var Payment[] */
    public array $receivedPayments = [];

    public function __construct(
        private readonly string $mode = 'success',
        private readonly string $paymentUrl = 'https://gateway.example.test/pay/fake-reference',
    ) {}

    public function name(): string
    {
        return 'merchant';
    }

    public function initiatePayment(Payment $payment): GatewayInitiationResult
    {
        $this->receivedPayments[] = $payment;

        $reference = 'FAKE-'.$payment->id;

        return match ($this->mode) {
            'timeout' => GatewayInitiationResult::failed($reference, 'gateway_timeout', 'The payment gateway did not respond in time.'),
            'failure' => GatewayInitiationResult::failed($reference, 'gateway_rejected', 'The payment gateway rejected the request.'),
            'invalid_response' => GatewayInitiationResult::failed($reference, 'invalid_gateway_response', 'The payment gateway returned an unexpected response.'),
            default => new GatewayInitiationResult(
                successful: true,
                transactionReference: $reference,
                gatewayReference: 'GW-'.$payment->id,
                paymentUrl: $this->paymentUrl,
                responseStatus: 'initiated',
            ),
        };
    }
}
