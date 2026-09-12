<?php

namespace App\Http\Resources;

use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Payment */
class PaymentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_schedule_id' => $this->payment_schedule_id,
            'payer' => new UserResource($this->whenLoaded('payer')),
            'required_amount' => $this->required_amount,
            'additional_infaq' => $this->additional_infaq,
            'total_amount' => $this->total_amount,
            'currency' => $this->currency,
            'status' => $this->status,
            'payment_method' => $this->payment_method,
            'paid_at' => $this->paid_at,
            'verified_at' => $this->verified_at,
            'verified_by' => $this->verified_by,
            'reference_number' => $this->reference_number,
            'notes' => $this->notes,
        ];
    }
}
