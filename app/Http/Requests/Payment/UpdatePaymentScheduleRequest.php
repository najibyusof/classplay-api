<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePaymentScheduleRequest extends FormRequest
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
            'due_date' => ['sometimes', 'date'],
            'status' => ['sometimes', 'in:upcoming,pending,partially_paid,paid,overdue,cancelled'],
        ];
    }
}
