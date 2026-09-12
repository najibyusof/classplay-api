<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class PaymentWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        $secret = config('services.payment_webhook.secret');

        return $secret !== null && hash_equals($secret, (string) $this->header('X-Webhook-Secret'));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'gateway_reference' => ['required', 'string'],
            'status' => ['required', 'in:paid,failed'],
            'response_code' => ['nullable', 'string', 'max:100'],
            'response_message' => ['nullable', 'string'],
        ];
    }
}
