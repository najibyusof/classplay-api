<?php

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class StorePaymentScheduleRequest extends FormRequest
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
            'class_participant_id' => ['required', 'integer', 'exists:class_participants,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'due_date' => ['required', 'date'],
            'required_amount' => ['required', 'numeric', 'min:0'],
            'status' => ['nullable', 'in:upcoming,pending,partially_paid,paid,overdue,cancelled'],
        ];
    }
}
