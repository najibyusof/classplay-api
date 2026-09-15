<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Phone numbers use real Malaysian prefixes so they pass the
     * `^\+60[0-9]{7,11}$` API rule and the mobile client's stricter
     * "valid Malaysian phone number" validation: mobile (011, 012-019)
     * and landline (03-09) numbers only — never invalid prefixes like 04x.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => '+60'.fake()->unique()->numerify(fake()->randomElement([
                '11########',   // 011 mobile (10 digits)
                '1#########',  // 012-019 mobile (9 digits)
                '3########',   // 03 landline (Klang Valley)
                '#[4-9]######', // 04-09 landlines
            ])),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'user_type' => 'student',
            'status' => 'active',
            'phone_verified_at' => now(),
        ];
    }

    /**
     * Indicate that the model's phone number should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'phone_verified_at' => null,
        ]);
    }

    /**
     * Indicate that the user has not set an initial password yet.
     */
    public function withoutPassword(): static
    {
        return $this->state(fn (array $attributes) => [
            'password' => null,
        ]);
    }
}
