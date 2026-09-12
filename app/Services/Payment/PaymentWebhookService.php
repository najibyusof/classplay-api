<?php

namespace App\Services\Payment;

use App\Contracts\Payment\WebhookVerifierInterface;
use App\Jobs\SendNotificationJob;
use App\Models\Notification;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class PaymentWebhookService
{
    /**
     * Allowed gateway/provider names.
     */
    private const KNOWN_PROVIDERS = [
        'merchant',
        'stripe',
        'toyyibpay',
        'billplz',
        'ipay88',
        'fpx',
        'duitnow',
        'manual',
        'bank_transfer',
        'qr',
    ];

    /**
     * Sensitive payload keys to scrub from response_payload before persisting to DB or logs.
     */
    private const SENSITIVE_KEYS = [
        'secret',
        'api_key',
        'password',
        'token',
        'authorization',
        'x-webhook-secret',
        'x-signature',
    ];

    public function __construct(private readonly WebhookVerifierInterface $verifier) {}

    public function processWebhook(Request $request, string $provider): PaymentWebhookResult
    {
        // 1. Provider Resolution
        if (! in_array(strtolower($provider), self::KNOWN_PROVIDERS, true)
            && ! config("payment.gateways.{$provider}")) {
            return PaymentWebhookResult::error('Unknown payment provider.', 404);
        }

        // 2. Webhook Signature Verification
        if (! $this->verifier->verifySignature($request, $provider)) {
            Log::warning("Webhook signature verification failed for provider: {$provider}");

            return PaymentWebhookResult::error('Invalid webhook signature.', 403);
        }

        // 3. Payload Extraction & Validation
        $reference = $request->input('gateway_reference')
            ?? $request->input('transaction_reference')
            ?? $request->input('reference');

        $statusInput = strtolower((string) ($request->input('status') ?? $request->input('event_type') ?? ''));

        if (empty($reference) || empty($statusInput)) {
            return PaymentWebhookResult::error('Invalid webhook payload: reference and status are required.', 422, [
                'reference' => 'The transaction reference is required.',
                'status' => 'The payment status is required.',
            ]);
        }

        $targetStatus = in_array($statusInput, ['paid', 'success', 'successful', 'completed'], true) ? 'paid' : 'failed';

        // 4. Find Transaction
        $transaction = PaymentTransaction::query()
            ->where(function ($query) use ($reference) {
                $query->where('gateway_reference', $reference)
                    ->orWhere('transaction_reference', $reference);
            })
            ->first();

        if (! $transaction) {
            return PaymentWebhookResult::error('Transaction not found.', 404);
        }

        // 5. Atomic DB Transaction with Row Locking
        return DB::transaction(function () use ($request, $transaction, $targetStatus, $provider) {
            $transaction = PaymentTransaction::query()->whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($transaction->payment_id)->lockForUpdate()->firstOrFail();
            $schedule = PaymentSchedule::query()->whereKey($payment->payment_schedule_id)->lockForUpdate()->firstOrFail();

            // 6. Idempotency & Replay Protection
            $eventId = $request->input('event_id');
            if ($eventId && ($transaction->response_payload['event_id'] ?? null) === $eventId) {
                return PaymentWebhookResult::ok('Webhook event already processed.');
            }

            if ($payment->status === 'paid' && $targetStatus === 'paid') {
                return PaymentWebhookResult::ok('Payment already confirmed.');
            }

            // 7. State Transition Validation
            if (! $this->isValidStateTransition($payment->status, $targetStatus)) {
                return PaymentWebhookResult::error("Invalid payment status transition from '{$payment->status}' to '{$targetStatus}'.", 422);
            }

            // 8. Amount Validation (if supplied by provider)
            $providerAmount = $request->input('amount') ?? $request->input('request_amount') ?? $request->input('total_amount');
            if ($providerAmount !== null) {
                $confirmedAmount = (float) $providerAmount;
                $expectedAmount = (float) $payment->total_amount;

                if (abs($confirmedAmount - $expectedAmount) > 0.001) {
                    $transaction->update([
                        'response_status' => 'failed',
                        'response_code' => 'amount_mismatch',
                        'response_message' => "Payment amount mismatch. Expected {$expectedAmount}, got {$confirmedAmount}.",
                        'completed_at' => now(),
                    ]);

                    $payment->update([
                        'status' => 'failed',
                        'notes' => 'Payment amount mismatch detected during webhook confirmation.',
                    ]);

                    return PaymentWebhookResult::error('Payment amount mismatch.', 422);
                }
            }

            // 9. Currency Validation (if supplied by provider)
            $providerCurrency = $request->input('currency');
            if ($providerCurrency !== null && strtoupper((string) $providerCurrency) !== strtoupper($payment->currency)) {
                return PaymentWebhookResult::error('Payment currency mismatch.', 422);
            }

            // 10. Update Transaction
            $sanitizedPayload = $this->sanitizePayload($request->all());
            if ($eventId) {
                $sanitizedPayload['event_id'] = $eventId;
            }

            $transaction->update([
                'response_status' => $targetStatus,
                'response_code' => $request->input('response_code'),
                'response_message' => $request->input('response_message'),
                'response_payload' => $sanitizedPayload,
                'completed_at' => now(),
            ]);

            // 11. Update Payment
            $payment->update([
                'status' => $targetStatus,
                'paid_at' => $targetStatus === 'paid' ? now() : null,
                'verified_at' => $targetStatus === 'paid' ? now() : null,
            ]);

            // 12. Update Payment Schedule (Atomic with Payment update)
            $totalPaid = (float) $schedule->payments()->where('status', 'paid')->sum('total_amount');
            if ($totalPaid >= (float) $schedule->required_amount) {
                $schedule->update(['status' => 'paid']);
            }

            // 13. Audit Logging (Scrubbed)
            Log::info("Webhook processed for provider [{$provider}] on transaction [{$transaction->transaction_reference}]", [
                'provider' => $provider,
                'transaction_reference' => $transaction->transaction_reference,
                'gateway_reference' => $transaction->gateway_reference,
                'payment_id' => $payment->id,
                'status' => $targetStatus,
            ]);

            // 14. Optional Notification Side Effect
            $notification = Notification::query()->create([
                'user_id' => $payment->payer_id,
                'type' => $targetStatus === 'paid' ? 'payment.success' : 'payment.failed',
                'title' => $targetStatus === 'paid' ? 'Payment successful' : 'Payment failed',
                'message' => $targetStatus === 'paid'
                    ? "Your payment of {$payment->total_amount} {$payment->currency} was successful."
                    : "Your payment of {$payment->total_amount} {$payment->currency} failed.",
                'related_type' => Payment::class,
                'related_id' => $payment->id,
                'sent_at' => now(),
            ]);

            SendNotificationJob::dispatch($notification);

            return PaymentWebhookResult::ok('Webhook processed successfully.');
        });
    }

    private function isValidStateTransition(string $currentStatus, string $targetStatus): bool
    {
        if ($currentStatus === $targetStatus) {
            return true;
        }

        return match ($currentStatus) {
            'initiated', 'pending', 'processing' => in_array($targetStatus, ['paid', 'failed'], true),
            'failed' => $targetStatus === 'paid', // Allow successful retry
            'paid' => false, // Cannot un-pay a paid payment via webhook
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        $sanitized = [];

        foreach ($payload as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizePayload($value);
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }
}
