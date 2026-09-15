<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Organization>
 */
class OrganizationFactory extends Factory
{
    protected $model = Organization::class;

    /**
     * Malaysian Islamic organization-style names for realistic seeded data.
     *
     * @var array<int, string>
     */
    private const ISLAMIC_ORG_NAMES = [
        'Pusat Tahfiz Al-Amin', 'Madrasah Nurul Iman', 'Sekolah Agama Rakyat Al-Falah',
        'Pusat Pengajian Quran As-Salam', 'Maahad Tahfiz Wal Tarbiah', 'Akademi Islam Al-Madinah',
        'Pusat Tuisyen Al-Ikhlas', 'Sekolah Rendah Islam At-Taqwa', 'Madrasah Al-Hikmah',
        'Pusat Pengajian Darul Ulum', 'Sekolah Agama Al-Munawwarah', 'Akademi Tahfiz Al-Quran',
        'Pusat Islam An-Nur', 'Madrasah Tarbiyah Islamiah', 'Sekolah Menengah Agama Al-Azhar',
        'Pusat Pengajian Ilmu Al-Bukhari', 'Akademi Al-Furqan', 'Maahad Islam Sultan Ismail',
        'Pusat Tahfiz Darul Quran', 'Sekolah Agama Nurul Huda', 'Madrasah Al-Khairiah',
        'Pusat Pengajian Ar-Rahman', 'Akademi Islam Al-Huda', 'Sekolah Rendah Agama Al-Firdaus',
        'Pusat Tuisyen Cilik Ilmu', 'Madrasah Asy-Syafi\'iyyah', 'Pusat Pengajian Al-Iman',
    ];

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->randomElement(self::ISLAMIC_ORG_NAMES),
            'code' => fake()->unique()->bothify('ORG-####'),
            'description' => fake()->sentence(),
            'logo_path' => null,
            'status' => 'active',
            'created_by' => User::factory()->state(['user_type' => 'admin']),
        ];
    }
}
