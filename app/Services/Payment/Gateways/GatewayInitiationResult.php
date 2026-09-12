<?php

namespace App\Services\Payment\Gateways;

/**
 * Immutable outcome of a gateway initiation attempt. Never carries provider
 * secrets — only the safe, client-facing subset of the provider's response.
 */
final readonly class GatewayInitiationResult
{
    public function __construct(
        public bool $successful,
        public string $transactionReference,
        public ?string $gatewayReference,
        public ?string $paymentUrl,
        public string $responseStatus,
        public ?string $responseCode = null,
        public ?string $responseMessage = null,
    ) {}

    public static function failed(string $transactionReference, string $responseCode, string $responseMessage): self
    {
        return new self(
            successful: false,
            transactionReference: $transactionReference,
            gatewayReference: null,
            paymentUrl: null,
            responseStatus: 'failed',
            responseCode: $responseCode,
            responseMessage: $responseMessage,
        );
    }
}
