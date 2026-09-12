<?php

namespace Database\Factories;

use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassParticipant>
 */
class ClassParticipantFactory extends Factory
{
    protected $model = ClassParticipant::class;

    public function definition(): array
    {
        return [
            'class_id' => ClassModel::factory(),
            'user_id' => User::factory()->state(['user_type' => 'student']),
            'participant_type' => 'student',
            'status' => 'active',
            'joined_at' => now(),
            'left_at' => null,
        ];
    }
}
