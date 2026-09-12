<?php

namespace App\Services\Payment;

use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\PaymentTransaction;
use App\Models\User;
use App\Services\Payment\Gateways\PaymentGatewayInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PaymentService
{
    /**
     * Methods that go through a manual review flow (bank transfer proof) start as
     * "pending"; "qr" simply displays configured bank/QR details (also "pending");
     * "merchant" is the only method that talks to a gateway, starting "initiated".
     */
    private const PENDING_METHODS = ['manual', 'bank_transfer', 'qr'];

    public function __construct(private readonly PaymentGatewayInterface $gateway) {}

    public function create(PaymentSchedule $schedule, User $payer, float $additionalInfaq, string $paymentMethod): PaymentInitiationResult
    {
        return DB::transaction(function () use ($schedule, $payer, $additionalInfaq, $paymentMethod) {
            // Lock the schedule row so two concurrent requests cannot both settle it.
            $schedule = PaymentSchedule::query()->whereKey($schedule->id)->lockForUpdate()->firstOrFail();

            if ($schedule->payments()->where('status', 'paid')->exists()) {
                throw new RuntimeException('This payment schedule has already been paid.');
            }

            $requiredAmount = (float) $schedule->required_amount;
            $totalAmount = $requiredAmount + $additionalInfaq;
            $status = in_array($paymentMethod, self::PENDING_METHODS, true) ? 'pending' : 'initiated';

            $payment = $schedule->payments()->create([
                'payer_id' => $payer->id,
                'required_amount' => $requiredAmount,
                'additional_infaq' => $additionalInfaq,
                'total_amount' => $totalAmount,
                'currency' => 'MYR',
                'status' => $status,
                'payment_method' => $paymentMethod,
                'reference_number' => (string) str()->uuid(),
            ]);

            if ($paymentMethod === 'qr') {
                $this->createPlaceholderTransaction($payment, 'qr');

                return new PaymentInitiationResult($payment, qrInfo: $this->qrInfo($schedule));
            }

            if ($paymentMethod === 'merchant') {
                return $this->initiateWithGateway($payment);
            }

            $this->createPlaceholderTransaction($payment, $paymentMethod);

            return new PaymentInitiationResult($payment);
        });
    }

    /**
     * Retry/explicitly initiate the gateway for an existing merchant payment.
     * Reuses an already-active transaction instead of creating a duplicate.
     */
    public function initiate(Payment $payment): PaymentInitiationResult
    {
        return DB::transaction(function () use ($payment) {
            $payment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if ($payment->payment_method !== 'merchant') {
                throw new RuntimeException('Only merchant payments can be initiated with the gateway.');
            }

            if ($payment->status === 'paid') {
                throw new RuntimeException('This payment has already been paid.');
            }

            $activeTransaction = $payment->transactions()
                ->where('gateway_name', $this->gateway->name())
                ->whereNotIn('response_status', ['failed'])
                ->whereNotNull('gateway_reference')
                ->latest('id')
                ->first();

            if ($activeTransaction) {
                return new PaymentInitiationResult(
                    $payment,
                    $this->gateway->name(),
                    $activeTransaction->response_payload['payment_url'] ?? null,
                    $activeTransaction->transaction_reference,
                );
            }

            return $this->initiateWithGateway($payment);
        });
    }

    private function initiateWithGateway(Payment $payment): PaymentInitiationResult
    {
        $transaction = $this->createPlaceholderTransaction($payment, $this->gateway->name());

        $result = $this->gateway->initiatePayment($payment);

        $transaction->update([
            'transaction_reference' => $result->transactionReference,
            'gateway_reference' => $result->gatewayReference,
            'response_status' => $result->responseStatus,
            'response_code' => $result->responseCode,
            'response_message' => $result->responseMessage,
            'response_payload' => $result->paymentUrl ? ['payment_url' => $result->paymentUrl] : null,
            'completed_at' => now(),
        ]);

        if (! $result->successful) {
            $payment->update(['status' => 'failed', 'notes' => $result->responseMessage]);
        }

        return new PaymentInitiationResult(
            $payment->fresh(),
            $this->gateway->name(),
            $result->paymentUrl,
            $result->transactionReference,
        );
    }

    private function createPlaceholderTransaction(Payment $payment, string $gatewayName): PaymentTransaction
    {
        return PaymentTransaction::query()->create([
            'payment_id' => $payment->id,
            'gateway_name' => $gatewayName,
            'request_amount' => $payment->total_amount,
            'response_status' => 'pending',
            'initiated_at' => now(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function qrInfo(PaymentSchedule $schedule): ?array
    {
        $setting = $schedule->classModel->paymentSetting;

        if (! $setting) {
            return null;
        }

        return [
            'bank' => [
                'name' => $setting->bank_name,
                'account_name' => $setting->bank_account_name,
                'account_number' => $setting->bank_account_number,
            ],
            'qr_code_path' => $setting->qr_code_path,
        ];
    }
}
