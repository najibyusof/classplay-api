<?php

namespace App\Contracts\Payment;

use Illuminate\Http\Request;

interface WebhookVerifierInterface
{
    /**
     * Verify the authenticity of an incoming webhook request from a payment provider.
     */
    public function verifySignature(Request $request, string $provider): bool;
}
