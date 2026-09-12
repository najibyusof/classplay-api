<?php

namespace App\Services\Payment\Gateways;

use App\Models\Payment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Configurable merchant provider adapter. No specific provider (Stripe, ToyyibPay,
 * Billplz, iPay88, FPX, DuitNow, ...) has been chosen yet, so this class only makes
 * generic, provider-agnostic assumptions: a POST to a configured URL that returns
 * a JSON body containing a "payment_url" (and optionally a "reference").
 *
 * When PAYMENT_GATEWAY_URL is not configured, it honestly reports that the
 * gateway is unavailable rather than pretending a payment succeeded.
 */
class MerchantPaymentGateway implements PaymentGatewayInterface
{
    /**
     * @param  array{merchant_id: ?string, api_key: ?string, secret: ?string, url: ?string, timeout: int}  $config
     */
    public function __construct(private readonly array $config) {}

    public function name(): string
    {
        return 'merchant';
    }

    public function initiatePayment(Payment $payment): GatewayInitiationResult
    {
        $reference = 'MER-'.Str::uuid();

        if (empty($this->config['url'])) {
            return GatewayInitiationResult::failed(
                $reference,
                'gateway_not_configured',
                'The payment gateway is not configured.'
            );
        }

        try {
            $response = Http::timeout($this->config['timeout'])
                ->withHeaders($this->signedHeaders($reference, $payment))
                ->post($this->config['url'], [
                    'merchant_id' => $this->config['merchant_id'],
                    'reference' => $reference,
                    'amount' => (string) $payment->total_amount,
                    'currency' => $payment->currency,
                ]);
        } catch (ConnectionException) {
            return GatewayInitiationResult::failed(
                $reference,
                'gateway_timeout',
                'The payment gateway did not respond in time.'
            );
        }

        if ($response->failed()) {
            return GatewayInitiationResult::failed(
                $reference,
                (string) $response->status(),
                'The payment gateway rejected the request.'
            );
        }

        $body = $response->json();

        if (! is_array($body) || empty($body['payment_url'])) {
            return GatewayInitiationResult::failed(
                $reference,
                'invalid_gateway_response',
                'The payment gateway returned an unexpected response.'
            );
        }

        return new GatewayInitiationResult(
            successful: true,
            transactionReference: $reference,
            gatewayReference: $body['reference'] ?? $reference,
            paymentUrl: $body['payment_url'],
            responseStatus: 'initiated',
        );
    }

    /**
     * @return array<string, string>
     */
    private function signedHeaders(string $reference, Payment $payment): array
    {
        $headers = [
            'Accept' => 'application/json',
        ];

        if (! empty($this->config['api_key'])) {
            $headers['Authorization'] = 'Bearer '.$this->config['api_key'];
        }

        if (! empty($this->config['secret'])) {
            $headers['X-Signature'] = hash_hmac(
                'sha256',
                $reference.'|'.$payment->total_amount,
                (string) $this->config['secret']
            );
        }

        return $headers;
    }
}
