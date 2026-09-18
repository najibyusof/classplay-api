<?php

namespace App\Http\Requests\ClassManagement;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClassRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:150'],
            'description' => ['nullable', 'string'],
            'teacher_name' => ['sometimes', 'string', 'max:150'],
            'status' => ['sometimes', 'in:draft,active,inactive,completed'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'day_of_week' => ['sometimes', 'integer', 'between:0,6'],
            'start_time' => ['sometimes', 'date_format:H:i'],
            'recurrence_type' => ['sometimes', 'in:weekly,fortnightly,monthly'],
            'payment_amount' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
