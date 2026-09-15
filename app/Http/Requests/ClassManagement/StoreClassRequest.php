<?php

namespace App\Http\Requests\ClassManagement;

use Illuminate\Foundation\Http\FormRequest;

class StoreClassRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'teacher_name' => ['required', 'string', 'max:150'],
            'status' => ['nullable', 'in:draft,active,inactive,completed'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'day_of_week' => ['required', 'integer', 'between:0,6'],
            'start_time' => ['required', 'date_format:H:i'],
            'recurrence_type' => ['required', 'in:weekly,fortnightly,monthly'],
            'payment_amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
