<?php

namespace Database\Factories;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\PaymentSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PaymentSchedule>
 */
class PaymentScheduleFactory extends Factory
{
    protected $model = PaymentSchedule::class;

    public function definition(): array
    {
        $periodStart = fake()->dateTimeBetween('-1 week', 'now');

        return [
            'class_id' => ClassModel::factory(),
            'class_participant_id' => ClassParticipant::factory(),
            'period_start' => $periodStart,
            'period_end' => (clone $periodStart)->modify('+6 days'),
            'due_date' => (clone $periodStart)->modify('+7 days'),
            'required_amount' => fake()->randomFloat(2, 20, 200),
            'status' => 'pending',
            'generated_at' => now(),
        ];
    }
}
