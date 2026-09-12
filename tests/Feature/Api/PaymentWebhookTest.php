<?php

namespace Tests\Feature\Api;

use App\Jobs\SendNotificationJob;
use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\ClassPaymentSetting;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\PaymentTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class PaymentWebhookTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.payment_webhook.secret' => 'webhook-secret-key']);
    }

    private function createPaymentFixture(float $amount = 100.00): array
    {
        $class = ClassModel::factory()->create(['status' => 'active']);
        ClassPaymentSetting::factory()->create(['class_id' => $class->id, 'required_amount' => $amount]);
        $student = User::factory()->create(['user_type' => 'student']);
        $participant = ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $student->id]);
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
            'required_amount' => $amount,
            'status' => 'pending',
        ]);
        $payment = Payment::factory()->create([
            'payment_schedule_id' => $schedule->id,
            'payer_id' => $student->id,
            'required_amount' => $amount,
            'additional_infaq' => 0,
            'total_amount' => $amount,
            'currency' => 'MYR',
            'status' => 'initiated',
            'payment_method' => 'merchant',
        ]);
        $transaction = PaymentTransaction::factory()->create([
            'payment_id' => $payment->id,
            'gateway_name' => 'merchant',
            'transaction_reference' => 'TXN-'.$payment->id,
            'gateway_reference' => 'GW-REF-'.$payment->id,
            'request_amount' => $amount,
            'response_status' => 'pending',
        ]);

        return compact('class', 'student', 'participant', 'schedule', 'payment', 'transaction');
    }

    // 1. Valid successful webhook.
    public function test_valid_successful_webhook_updates_payment_and_schedule(): void
    {
        Bus::fake();
        ['payment' => $payment, 'schedule' => $schedule, 'transaction' => $transaction] = $this->createPaymentFixture(100.00);

        $response = $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'paid',
                'amount' => 100.00,
                'currency' => 'MYR',
            ]);

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->paid_at);
        $this->assertSame('paid', $schedule->fresh()->status);
        $this->assertSame('paid', $transaction->fresh()->response_status);

        Bus::assertDispatched(SendNotificationJob::class);
    }

    // 2. Invalid signature returns 401 or 403.
    public function test_webhook_with_invalid_signature_returns_403(): void
    {
        ['transaction' => $transaction] = $this->createPaymentFixture();

        $this->withHeaders(['X-Webhook-Secret' => 'wrong-secret'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'paid',
            ])->assertForbidden()
            ->assertJsonPath('success', false);
    }

    // 3. Missing signature returns 403 when secret is configured.
    public function test_webhook_with_missing_signature_returns_403(): void
    {
        ['transaction' => $transaction] = $this->createPaymentFixture();

        $this->postJson('/api/v1/payment/webhook/merchant', [
            'gateway_reference' => $transaction->gateway_reference,
            'status' => 'paid',
        ])->assertForbidden();
    }

    // 4. Unknown provider returns 404.
    public function test_webhook_with_unknown_provider_returns_404(): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/unknown_gateway', [
                'gateway_reference' => 'GW-123',
                'status' => 'paid',
            ])->assertNotFound();
    }

    // 5. Unknown transaction returns 404.
    public function test_webhook_with_unknown_transaction_returns_404(): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => 'GW-NON-EXISTENT',
                'status' => 'paid',
            ])->assertNotFound();
    }

    // 6. Invalid payload returns 422.
    public function test_webhook_with_invalid_payload_returns_422(): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'amount' => 100.00,
            ])->assertUnprocessable();
    }

    // 7. Invalid amount mismatch is rejected (marks payment as failed).
    public function test_webhook_with_amount_mismatch_is_rejected(): void
    {
        ['payment' => $payment, 'schedule' => $schedule, 'transaction' => $transaction] = $this->createPaymentFixture(100.00);

        $response = $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'paid',
                'amount' => 50.00, // Expected 100.00
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Payment amount mismatch.');

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertNotEquals('paid', $schedule->fresh()->status);
    }

    // 8. Invalid currency returns 422.
    public function test_webhook_with_currency_mismatch_returns_422(): void
    {
        ['transaction' => $transaction] = $this->createPaymentFixture();

        $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'paid',
                'amount' => 100.00,
                'currency' => 'USD',
            ])->assertStatus(422)
            ->assertJsonPath('message', 'Payment currency mismatch.');
    }

    // 9. Successful payment updates Payment model.
    public function test_successful_webhook_updates_payment_fields(): void
    {
        ['payment' => $payment, 'transaction' => $transaction] = $this->createPaymentFixture();

        $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'paid',
            ])->assertOk();

        $freshPayment = $payment->fresh();
        $this->assertSame('paid', $freshPayment->status);
        $this->assertNotNull($freshPayment->paid_at);
        $this->assertNotNull($freshPayment->verified_at);
    }

    // 10. Successful payment updates PaymentSchedule model.
    public function test_successful_webhook_updates_payment_schedule_status(): void
    {
        ['schedule' => $schedule, 'transaction' => $transaction] = $this->createPaymentFixture(75.00);

        $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'paid',
                'amount' => 75.00,
            ])->assertOk();

        $this->assertSame('paid', $schedule->fresh()->status);
    }

    // 11. Duplicate webhook processing is idempotent.
    public function test_duplicate_webhook_is_idempotent(): void
    {
        ['payment' => $payment, 'transaction' => $transaction] = $this->createPaymentFixture();

        // First attempt
        $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'paid',
            ])->assertOk();

        $paidAtFirst = $payment->fresh()->paid_at;

        // Second duplicate attempt
        $response = $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'paid',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Payment already confirmed.');

        // Status remains paid and paid_at is unchanged
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertEquals($paidAtFirst, $payment->fresh()->paid_at);
    }

    // 12. Replay protection works with event_id.
    public function test_replay_protection_with_event_id(): void
    {
        ['transaction' => $transaction] = $this->createPaymentFixture();

        // First call with event_id
        $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'paid',
                'event_id' => 'EVT-9999',
            ])->assertOk();

        // Replay call with exact same event_id
        $response = $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'paid',
                'event_id' => 'EVT-9999',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Webhook event already processed.');
    }

    // 13. Failed payment updates Payment and PaymentTransaction status correctly.
    public function test_failed_webhook_updates_payment_status_to_failed(): void
    {
        ['payment' => $payment, 'schedule' => $schedule, 'transaction' => $transaction] = $this->createPaymentFixture();

        $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'failed',
                'response_code' => 'insufficient_funds',
                'response_message' => 'Card has insufficient funds.',
            ])->assertOk();

        $this->assertSame('failed', $payment->fresh()->status);
        $this->assertSame('failed', $transaction->fresh()->response_status);
        $this->assertSame('insufficient_funds', $transaction->fresh()->response_code);
        $this->assertNotSame('paid', $schedule->fresh()->status);
    }

    // 14. Invalid state transition is rejected.
    public function test_invalid_state_transition_is_rejected(): void
    {
        ['payment' => $payment, 'transaction' => $transaction] = $this->createPaymentFixture();

        // Mark payment as paid manually
        $payment->update(['status' => 'paid']);

        // Webhook trying to reset paid payment to failed/pending
        $response = $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'failed',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // 15. Sensitive credentials are not stored in response_payload or logged.
    public function test_sensitive_credentials_are_scrubbed_from_payload_and_logs(): void
    {
        Log::spy();
        ['transaction' => $transaction] = $this->createPaymentFixture();

        $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'paid',
                'api_key' => 'super-secret-api-key',
                'secret' => 'super-secret-value',
                'password' => 'super-secret-password',
                'response_code' => '200_OK',
            ])->assertOk();

        $payload = $transaction->fresh()->response_payload;
        $this->assertArrayNotHasKey('api_key', $payload);
        $this->assertArrayNotHasKey('secret', $payload);
        $this->assertArrayNotHasKey('password', $payload);

        Log::shouldNotHaveReceived('info', fn (...$args) => str_contains(json_encode($args), 'super-secret-api-key'));
    }

    // 16. Webhook does not require Sanctum authentication.
    public function test_webhook_does_not_require_sanctum_auth(): void
    {
        ['transaction' => $transaction] = $this->createPaymentFixture();

        $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'paid',
            ])->assertOk();
    }

    // 17. Partial payment behavior: schedule only marked paid when total paid >= required_amount.
    public function test_schedule_is_only_marked_paid_when_total_paid_meets_required_amount(): void
    {
        $class = ClassModel::factory()->create(['status' => 'active']);
        $student = User::factory()->create(['user_type' => 'student']);
        $participant = ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $student->id]);
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
            'required_amount' => 100.00,
            'status' => 'pending',
        ]);

        // Payment 1: RM 40.00
        $payment1 = Payment::factory()->create([
            'payment_schedule_id' => $schedule->id,
            'payer_id' => $student->id,
            'required_amount' => 100.00,
            'total_amount' => 40.00,
            'status' => 'initiated',
        ]);
        $transaction1 = PaymentTransaction::factory()->create([
            'payment_id' => $payment1->id,
            'gateway_name' => 'merchant',
            'gateway_reference' => 'GW-PART-1',
            'request_amount' => 40.00,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction1->gateway_reference,
                'status' => 'paid',
                'amount' => 40.00,
            ])->assertOk();

        // Payment 1 is paid, but schedule is still pending (40 < 100)
        $this->assertSame('paid', $payment1->fresh()->status);
        $this->assertSame('pending', $schedule->fresh()->status);

        // Payment 2: RM 60.00
        $payment2 = Payment::factory()->create([
            'payment_schedule_id' => $schedule->id,
            'payer_id' => $student->id,
            'required_amount' => 100.00,
            'total_amount' => 60.00,
            'status' => 'initiated',
        ]);
        $transaction2 = PaymentTransaction::factory()->create([
            'payment_id' => $payment2->id,
            'gateway_name' => 'merchant',
            'gateway_reference' => 'GW-PART-2',
            'request_amount' => 60.00,
        ]);

        $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
            ->postJson('/api/v1/payment/webhook/merchant', [
                'gateway_reference' => $transaction2->gateway_reference,
                'status' => 'paid',
                'amount' => 60.00,
            ])->assertOk();

        // Now total paid = 40 + 60 = 100 >= 100, schedule becomes paid!
        $this->assertSame('paid', $payment2->fresh()->status);
        $this->assertSame('paid', $schedule->fresh()->status);
    }

    // 18. Transaction atomicity: rollback if DB update fails.
    public function test_webhook_processing_is_atomic(): void
    {
        ['payment' => $payment, 'schedule' => $schedule, 'transaction' => $transaction] = $this->createPaymentFixture();

        // Simulate DB failure by causing a transaction failure if schedule fails
        DB::shouldReceive('transaction')
            ->once()
            ->andThrow(new \RuntimeException('Database failure during webhook transaction'));

        try {
            $this->withHeaders(['X-Webhook-Secret' => 'webhook-secret-key'])
                ->postJson('/api/v1/payment/webhook/merchant', [
                    'gateway_reference' => $transaction->gateway_reference,
                    'status' => 'paid',
                ]);
        } catch (\RuntimeException $e) {
            $this->assertSame('Database failure during webhook transaction', $e->getMessage());
        }

        $this->assertSame('initiated', $payment->fresh()->status);
        $this->assertSame('pending', $schedule->fresh()->status);
    }
}
