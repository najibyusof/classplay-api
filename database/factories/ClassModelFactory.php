<?php

namespace Database\Factories;

use App\Models\ClassModel;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassModel>
 */
class ClassModelFactory extends Factory
{
    protected $model = ClassModel::class;

    /**
     * Malay Islamic class names for realistic seeded data.
     *
     * @var array<int, string>
     */
    private const ISLAMIC_CLASS_NAMES = [
        'Kelas Quran', 'Kelas Iqra', 'Kelas Tajwid', 'Kelas Fardhu Ain',
        'Kelas Mengaji Al-Quran', 'Kelas Jawi', 'Kelas Khatam Quran', 'Kelas Hafazan Juz 30',
        'Kelas Tasmi\'', 'Kelas Hadith', 'Kelas Sirah Nabawiyah', 'Kelas Akhlak Islamiah',
        'Kelas Bahasa Arab', 'Kelas Feqah', 'Kelas Tauhid', 'Kelas Solat',
        'Kelas Puasa', 'Kelas Zakat', 'Kelas Qiraati', 'Kelas Tilawah',
        'Kelas Muallaf', 'Kelas Pra Tahfiz', 'Kelas Tahfizul Quran', 'Kelas KAFA',
    ];

    public function definition(): array
    {
        return [
            'organization_id' => Organization::factory(),
            'name' => fake()->randomElement(self::ISLAMIC_CLASS_NAMES),
            'description' => 'Kelas pengajian Islam mingguan',
            'teacher_name' => fake()->randomElement([
                'Ustaz Ahmad Fauzi', 'Ustazah Siti Hajar', 'Cikgu Nurul Ain',
                'Ustaz Mohd Ridwan', 'Ustazah Nor Azlina', 'Cikgu Muhammad Haziq',
                'Ustaz Abdul Rahman', 'Ustazah Wan Nurul', 'Cikgu Siti Zaleha',
            ]),
            'day_of_week' => fake()->numberBetween(0, 6),
            'start_time' => fake()->time('H:i'),
            'frequency' => fake()->randomElement(['weekly', 'fortnightly', 'monthly']),
            'payment_amount' => fake()->randomElement([30, 50, 80, 100, 150]),
            'status' => 'active',
            'start_date' => fake()->dateTimeBetween('-1 month', 'now'),
            'end_date' => null,
            'created_by' => User::factory()->state(['user_type' => 'admin']),
        ];
    }
}
