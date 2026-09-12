<?php

namespace Tests\Feature\Api;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\ClassPaymentSetting;
use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\User;
use App\Services\Payment\Gateways\MerchantPaymentGateway;
use App\Services\Payment\Gateways\PaymentGatewayInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Sanctum\Sanctum;
use Tests\Support\FakePaymentGateway;
use Tests\TestCase;

class PaymentGatewayApiTest extends TestCase
{
    use RefreshDatabase;

    private function bindGateway(string $mode = 'success'): FakePaymentGateway
    {
        $gateway = new FakePaymentGateway($mode);
        $this->app->instance(PaymentGatewayInterface::class, $gateway);

        return $gateway;
    }

    private function studentWithSchedule(array $settingAttributes = []): array
    {
        $class = ClassModel::factory()->create(['status' => 'active']);
        $setting = ClassPaymentSetting::factory()->create(array_merge([
            'class_id' => $class->id,
            'required_amount' => 50,
            'bank_name' => 'Test Bank',
            'bank_account_name' => 'Test Account',
            'bank_account_number' => '1234567890',
            'qr_code_path' => 'qr-codes/test.png',
        ], $settingAttributes));
        $student = User::factory()->create(['user_type' => 'student']);
        $participant = ClassParticipant::factory()->create(['class_id' => $class->id, 'user_id' => $student->id]);
        $schedule = PaymentSchedule::factory()->create([
            'class_id' => $class->id,
            'class_participant_id' => $participant->id,
            'required_amount' => 50,
            'status' => 'pending',
        ]);

        return compact('class', 'setting', 'student', 'participant', 'schedule');
    }

    // 1. The gateway interface works end-to-end via the container binding.
    public function test_gateway_interface_resolves_and_initiates(): void
    {
        $gateway = $this->bindGateway();

        $this->assertSame($gateway, app(PaymentGatewayInterface::class));
        $this->assertSame('merchant', $gateway->name());
    }

    // 2. The merchant gateway is resolved by default from the container binding.
    public function test_merchant_gateway_is_resolved_by_default(): void
    {
        $this->assertInstanceOf(
            MerchantPaymentGateway::class,
            app(PaymentGatewayInterface::class)
        );
    }

    // 3. The gateway receives the payment's authoritative total_amount, never a client-supplied amount.
    public function test_gateway_receives_authoritative_amount(): void
    {
        $gateway = $this->bindGateway();
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'merchant',
        ])->assertCreated();

        $this->assertCount(1, $gateway->receivedPayments);
        $this->assertEquals(50.0, (float) $gateway->receivedPayments[0]->total_amount);
    }

    // 4. A successful merchant initiation returns a payment_url.
    public function test_merchant_payment_returns_payment_url(): void
    {
        $this->bindGateway();
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'merchant',
        ])->assertCreated();

        $response->assertJsonPath('data.gateway.name', 'merchant');
        $this->assertNotEmpty($response->json('data.gateway.payment_url'));
    }

    // 5. A gateway transaction row is created for the payment.
    public function test_gateway_transaction_is_created(): void
    {
        $this->bindGateway();
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'merchant',
        ])->assertCreated();

        $payment = Payment::findOrFail($response->json('data.payment.id'));
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'gateway_name' => 'merchant',
        ]);
    }

    // 6. The transaction reference is stored on the transaction row.
    public function test_transaction_reference_is_stored(): void
    {
        $this->bindGateway();
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'merchant',
        ])->assertCreated();

        $reference = $response->json('data.gateway.transaction_reference');
        $this->assertNotEmpty($reference);
        $this->assertDatabaseHas('payment_transactions', ['transaction_reference' => $reference]);
    }

    // 7. A gateway failure is handled gracefully (no 500, payment marked failed, safe message stored).
    public function test_gateway_failure_is_handled_gracefully(): void
    {
        $this->bindGateway('failure');
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'merchant',
        ])->assertCreated();

        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', 'Payment gateway initiation failed.');

        $payment = Payment::findOrFail($response->json('data.payment.id'));
        $this->assertSame('failed', $payment->status);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'response_status' => 'failed',
            'response_code' => 'gateway_rejected',
        ]);
    }

    // 8. A gateway timeout is handled the same safe way as any other failure.
    public function test_gateway_timeout_is_handled_gracefully(): void
    {
        $this->bindGateway('timeout');
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'merchant',
        ])->assertCreated();

        $payment = Payment::findOrFail($response->json('data.payment.id'));
        $this->assertSame('failed', $payment->status);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'response_code' => 'gateway_timeout',
        ]);
    }

    // 9. Re-initiating an already-active merchant payment reuses the existing transaction.
    public function test_existing_active_transaction_is_not_duplicated(): void
    {
        $gateway = $this->bindGateway();
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $created = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'merchant',
        ])->assertCreated();

        $paymentId = $created->json('data.payment.id');
        $firstReference = $created->json('data.gateway.transaction_reference');

        $this->postJson("/api/v1/student/payments/{$paymentId}/initiate")
            ->assertOk()
            ->assertJsonPath('data.gateway.transaction_reference', $firstReference);

        $this->assertSame(1, $gateway->receivedPayments === [] ? 0 : count($gateway->receivedPayments));
        $this->assertDatabaseCount('payment_transactions', 1);
    }

    // 10. A "qr" payment returns the configured bank/QR information without contacting the gateway.
    public function test_qr_payment_returns_configured_information(): void
    {
        $gateway = $this->bindGateway();
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'qr',
        ])->assertCreated();

        $response->assertJsonPath('data.qr.bank.name', 'Test Bank');
        $response->assertJsonPath('data.qr.bank.account_number', '1234567890');
        $response->assertJsonPath('data.qr.qr_code_path', 'qr-codes/test.png');
        $this->assertEmpty($gateway->receivedPayments);
    }

    // 11. Gateway secrets are never present in the API response.
    public function test_gateway_secrets_are_never_returned(): void
    {
        config(['payment.gateways.merchant.secret' => 'super-secret-value']);
        config(['payment.gateways.merchant.api_key' => 'super-secret-api-key']);
        $this->bindGateway();
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'merchant',
        ])->assertCreated();

        $this->assertStringNotContainsString('super-secret-value', $response->getContent());
        $this->assertStringNotContainsString('super-secret-api-key', $response->getContent());
    }

    // 12. Gateway secrets are never written to the log.
    public function test_gateway_secrets_are_never_logged(): void
    {
        config(['payment.gateways.merchant.secret' => 'super-secret-value']);
        Log::spy();
        $this->bindGateway('failure');
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'merchant',
        ])->assertCreated();

        Log::shouldNotHaveReceived('info', fn (...$args) => str_contains(json_encode($args), 'super-secret-value'));
        Log::shouldNotHaveReceived('error', fn (...$args) => str_contains(json_encode($args), 'super-secret-value'));
    }

    // 13. A payment is never marked "paid" merely by initiating the gateway.
    public function test_payment_is_not_marked_paid_during_initiation(): void
    {
        $this->bindGateway();
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'merchant',
        ])->assertCreated();

        $this->assertNotSame('paid', $response->json('data.payment.status'));
    }

    // 14. A user cannot initiate/retry another user's payment.
    public function test_unauthorized_user_cannot_initiate_another_users_payment(): void
    {
        $this->bindGateway();
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $created = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'merchant',
        ])->assertCreated();

        $paymentId = $created->json('data.payment.id');
        $otherStudent = User::factory()->create(['user_type' => 'student']);

        Sanctum::actingAs($otherStudent);
        $this->postJson("/api/v1/student/payments/{$paymentId}/initiate")->assertForbidden();
    }

    // 15. An invalid/unexpected gateway response is treated as a failure, not a crash.
    public function test_invalid_gateway_response_is_treated_as_failure(): void
    {
        $this->bindGateway('invalid_response');
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'merchant',
        ])->assertCreated();

        $payment = Payment::findOrFail($response->json('data.payment.id'));
        $this->assertSame('failed', $payment->status);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'response_code' => 'invalid_gateway_response',
        ]);
    }

    // Bonus: retrying initiate() on a payment that isn't a merchant method is rejected.
    public function test_initiate_rejects_non_merchant_payment(): void
    {
        $this->bindGateway();
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $created = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'manual',
        ])->assertCreated();

        $this->postJson("/api/v1/student/payments/{$created->json('data.payment.id')}/initiate")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // Bonus: the merchant gateway itself returns a safe "not configured" failure when no URL is set.
    public function test_real_merchant_gateway_reports_not_configured_without_url(): void
    {
        config(['payment.gateways.merchant.url' => null]);
        ['student' => $student, 'schedule' => $schedule] = $this->studentWithSchedule();

        Sanctum::actingAs($student);
        $response = $this->postJson("/api/v1/student/payment-schedules/{$schedule->id}/payments", [
            'payment_method' => 'merchant',
        ])->assertCreated();

        $payment = Payment::findOrFail($response->json('data.payment.id'));
        $this->assertSame('failed', $payment->status);
        $this->assertDatabaseHas('payment_transactions', [
            'payment_id' => $payment->id,
            'response_code' => 'gateway_not_configured',
        ]);
    }
}
