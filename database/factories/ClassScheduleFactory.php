<?php

namespace Database\Factories;

use App\Models\ClassModel;
use App\Models\ClassSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassSchedule>
 */
class ClassScheduleFactory extends Factory
{
    protected $model = ClassSchedule::class;

    public function definition(): array
    {
        return [
            'class_id' => ClassModel::factory(),
            'day_of_week' => fake()->numberBetween(0, 6),
            'start_time' => '09:00:00',
            'end_time' => '10:00:00',
            'timezone' => 'Asia/Kuala_Lumpur',
            'recurrence_type' => 'weekly',
            'effective_from' => fake()->dateTimeBetween('-1 month', 'now'),
            'effective_until' => null,
        ];
    }
}
