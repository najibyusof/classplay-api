<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentTransactionRequest extends FormRequest
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
            'gateway_name' => ['required', 'string', 'max:100'],
            'transaction_reference' => ['nullable', 'string', 'max:200'],
            'gateway_reference' => ['nullable', 'string', 'max:200'],
            'request_amount' => ['required', 'numeric', 'min:0'],
            'response_status' => ['nullable', 'string', 'max:100'],
            'response_code' => ['nullable', 'string', 'max:100'],
            'response_message' => ['nullable', 'string'],
            'request_payload' => ['nullable', 'array'],
            'response_payload' => ['nullable', 'array'],
        ];
    }
}
