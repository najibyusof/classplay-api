<?php

namespace App\Services\Payment\Verifiers;

use App\Contracts\Payment\WebhookVerifierInterface;
use Illuminate\Http\Request;

class PaymentWebhookVerifier implements WebhookVerifierInterface
{
    public function verifySignature(Request $request, string $provider): bool
    {
        $secret = config("payment.gateways.{$provider}.secret")
            ?: config('services.payment_webhook.secret');

        // If no secret is configured at all in the environment, fallback to open/testing or check header presence
        if ($secret === null || $secret === '') {
            return true;
        }

        $headerSecret = $request->header('X-Webhook-Secret');
        if ($headerSecret !== null) {
            return hash_equals((string) $secret, (string) $headerSecret);
        }

        $headerSignature = $request->header('X-Webhook-Signature') ?? $request->header('X-Signature');
        if ($headerSignature !== null) {
            $computedSignature = hash_hmac('sha256', $request->getContent(), (string) $secret);

            return hash_equals($computedSignature, (string) $headerSignature);
        }

        // Secret is configured but no signature/secret header was provided.
        return false;
    }
}
