<?php

namespace App\Http\Resources;

use App\Models\ClassPaymentSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClassPaymentSetting */
class ClassPaymentSettingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_id' => $this->class_id,
            'required_amount' => $this->required_amount,
            'currency' => $this->currency,
            'payment_frequency' => $this->payment_frequency,
            'bank_name' => $this->bank_name,
            'bank_account_name' => $this->bank_account_name,
            'bank_account_number' => $this->bank_account_number,
            'qr_code_path' => $this->qr_code_path,
            'qr_code_url' => $this->qr_code_url,
            'merchant_payment_url' => $this->merchant_payment_url,
            'allow_additional_infaq' => $this->allow_additional_infaq,
            'minimum_infaq' => $this->minimum_infaq,
            'maximum_infaq' => $this->maximum_infaq,
            'reminder_enabled' => $this->reminder_enabled,
            'reminder_days_before' => $this->reminder_days_before,
            'reminder_days_after' => $this->reminder_days_after,
        ];
    }
}
