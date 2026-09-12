<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StorePaymentRequest extends FormRequest
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
            'additional_infaq' => ['nullable', 'numeric', 'min:0'],
            'payment_method' => ['required', 'in:qr,merchant,bank_transfer,manual'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $schedule = $this->route('paymentSchedule') ?? $this->route('schedule');
            $setting = $schedule?->classModel?->paymentSetting;

            if (! $setting) {
                return;
            }

            $infaq = (float) ($this->input('additional_infaq') ?? 0);

            if ($infaq <= 0) {
                return;
            }

            if (! $setting->allow_additional_infaq) {
                $validator->errors()->add('additional_infaq', 'Additional infaq is not allowed for this class.');

                return;
            }

            if ($setting->minimum_infaq !== null && $infaq < (float) $setting->minimum_infaq) {
                $validator->errors()->add('additional_infaq', "Additional infaq must be at least {$setting->minimum_infaq}.");
            }

            if ($setting->maximum_infaq !== null && $infaq > (float) $setting->maximum_infaq) {
                $validator->errors()->add('additional_infaq', "Additional infaq must not exceed {$setting->maximum_infaq}.");
            }
        });
    }
}
