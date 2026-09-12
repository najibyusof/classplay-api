<?php

namespace Database\Factories;

use App\Models\ClassModel;
use App\Models\ClassPaymentSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClassPaymentSetting>
 */
class ClassPaymentSettingFactory extends Factory
{
    protected $model = ClassPaymentSetting::class;

    public function definition(): array
    {
        return [
            'class_id' => ClassModel::factory(),
            'required_amount' => fake()->randomFloat(2, 20, 200),
            'currency' => 'MYR',
            'payment_frequency' => 'weekly',
            'bank_name' => fake()->company(),
            'bank_account_name' => fake()->name(),
            'bank_account_number' => fake()->bankAccountNumber(),
            'qr_code_path' => null,
            'merchant_payment_url' => null,
            'allow_additional_infaq' => true,
            'minimum_infaq' => 5,
            'maximum_infaq' => 100,
            'reminder_enabled' => true,
            'reminder_days_before' => 3,
            'reminder_days_after' => 3,
        ];
    }
}
