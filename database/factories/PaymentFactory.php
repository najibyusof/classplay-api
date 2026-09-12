<?php

namespace Database\Factories;

use App\Models\Payment;
use App\Models\PaymentSchedule;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $requiredAmount = fake()->randomFloat(2, 20, 200);
        $additionalInfaq = 0;

        return [
            'payment_schedule_id' => PaymentSchedule::factory(),
            'payer_id' => User::factory(),
            'required_amount' => $requiredAmount,
            'additional_infaq' => $additionalInfaq,
            'total_amount' => $requiredAmount + $additionalInfaq,
            'currency' => 'MYR',
            'status' => 'initiated',
            'payment_method' => 'manual',
            'paid_at' => null,
            'verified_at' => null,
            'verified_by' => null,
            'reference_number' => fake()->unique()->bothify('PAY-########'),
            'notes' => null,
        ];
    }
}
