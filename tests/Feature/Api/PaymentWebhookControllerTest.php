<?php

namespace Tests\Feature\Api;

use App\Jobs\SendNotificationJob;
use App\Models\Payment;
use App\Models\PaymentTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PaymentWebhookControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.payment_webhook.secret' => 'test-secret']);
    }

    public function test_webhook_without_valid_secret_is_forbidden(): void
    {
        $transaction = PaymentTransaction::factory()->create(['gateway_name' => 'stripe']);

        $this->postJson('/api/v1/webhooks/payments/stripe', [
            'gateway_reference' => $transaction->gateway_reference,
            'status' => 'paid',
        ])->assertForbidden();
    }

    public function test_valid_webhook_marks_payment_as_paid(): void
    {
        Bus::fake();

        $payment = Payment::factory()->create(['status' => 'processing']);
        $transaction = PaymentTransaction::factory()->create([
            'payment_id' => $payment->id,
            'gateway_name' => 'stripe',
        ]);

        $response = $this->withHeaders(['X-Webhook-Secret' => 'test-secret'])
            ->postJson('/api/v1/webhooks/payments/stripe', [
                'gateway_reference' => $transaction->gateway_reference,
                'status' => 'paid',
            ]);

        $response->assertOk();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertDatabaseHas('notifications', [
            'user_id' => $payment->payer_id,
            'type' => 'payment.success',
        ]);
        Bus::assertDispatched(SendNotificationJob::class);
    }

    public function test_webhook_for_unknown_transaction_returns_404(): void
    {
        $this->withHeaders(['X-Webhook-Secret' => 'test-secret'])
            ->postJson('/api/v1/webhooks/payments/stripe', [
                'gateway_reference' => 'unknown-reference',
                'status' => 'paid',
            ])->assertNotFound();
    }
}
