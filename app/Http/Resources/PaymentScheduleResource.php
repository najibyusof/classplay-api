<?php

namespace App\Http\Resources;

use App\Models\PaymentSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentSchedule */
class PaymentScheduleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_id' => $this->class_id,
            'class' => $this->whenLoaded('classModel', fn () => [
                'id' => $this->classModel->id,
                'name' => $this->classModel->name,
            ]),
            'class_participant_id' => $this->class_participant_id,
            'period_start' => $this->period_start,
            'period_end' => $this->period_end,
            'due_date' => $this->due_date,
            'required_amount' => $this->required_amount,
            'status' => $this->status,
            'payment_status' => $this->whenLoaded('payments', fn () => $this->latestPaymentStatus()),
            'payment_options' => $this->whenLoaded('classModel', fn () => $this->paymentOptions()),
            'generated_at' => $this->generated_at,
        ];
    }

    private function latestPaymentStatus(): ?string
    {
        return $this->payments->sortByDesc('created_at')->first()?->status;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function paymentOptions(): ?array
    {
        $setting = $this->classModel->paymentSetting;

        if (! $setting) {
            return null;
        }

        return [
            'currency' => $setting->currency,
            'allow_additional_infaq' => $setting->allow_additional_infaq,
            'minimum_infaq' => $setting->minimum_infaq,
            'maximum_infaq' => $setting->maximum_infaq,
            'bank_name' => $setting->bank_name,
            'bank_account_name' => $setting->bank_account_name,
            'bank_account_number' => $setting->bank_account_number,
            'qr_code_path' => $setting->qr_code_path,
            'merchant_payment_url' => $setting->merchant_payment_url,
        ];
    }
}
