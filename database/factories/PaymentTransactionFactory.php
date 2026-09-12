<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentTransaction>
 */
class PaymentTransactionFactory extends Factory
{
    protected $model = PaymentTransaction::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'gateway_name' => fake()->randomElement(['stripe', 'billplz', 'toyyibpay']),
            'transaction_reference' => fake()->unique()->bothify('TXN-########'),
            'gateway_reference' => fake()->unique()->bothify('GW-########'),
            'request_amount' => fake()->randomFloat(2, 20, 200),
            'response_status' => 'pending',
            'response_code' => null,
            'response_message' => null,
            'request_payload' => null,
            'response_payload' => null,
            'initiated_at' => now(),
            'completed_at' => null,
        ];
    }
}
