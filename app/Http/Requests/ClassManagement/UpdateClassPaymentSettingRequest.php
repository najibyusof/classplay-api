<?php

namespace App\Http\Requests\ClassManagement;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClassPaymentSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'required_amount' => ['sometimes', 'numeric', 'min:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'payment_frequency' => ['sometimes', 'in:weekly,fortnightly,monthly'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account_name' => ['nullable', 'string', 'max:150'],
            'bank_account_number' => ['nullable', 'string', 'max:100'],
            'qr_code_path' => ['nullable', 'string', 'max:500'],
            'merchant_payment_url' => ['nullable', 'string'],
            'allow_additional_infaq' => ['nullable', 'boolean'],
            'minimum_infaq' => ['nullable', 'numeric', 'min:0'],
            'maximum_infaq' => ['nullable', 'numeric', 'gte:minimum_infaq'],
            'reminder_enabled' => ['nullable', 'boolean'],
            'reminder_days_before' => ['nullable', 'integer', 'min:0'],
            'reminder_days_after' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
