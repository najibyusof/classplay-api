<?php

namespace App\Http\Resources;

use App\Models\PaymentTransaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PaymentTransaction */
class PaymentTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'payment_id' => $this->payment_id,
            'gateway_name' => $this->gateway_name,
            'transaction_reference' => $this->transaction_reference,
            'gateway_reference' => $this->gateway_reference,
            'request_amount' => $this->request_amount,
            'response_status' => $this->response_status,
            'response_code' => $this->response_code,
            'response_message' => $this->response_message,
            'initiated_at' => $this->initiated_at,
            'completed_at' => $this->completed_at,
        ];
    }
}
