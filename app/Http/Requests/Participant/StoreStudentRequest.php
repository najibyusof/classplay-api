<?php

namespace App\Http\Requests\Participant;

use App\Services\PhoneNumberNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class StoreStudentRequest extends FormRequest
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
            'phone' => ['required', 'string', 'regex:/^\+60[0-9]{7,11}$/', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:150'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('phone')) {
            $this->merge(['phone' => PhoneNumberNormalizer::normalize((string) $this->input('phone'))]);
        }
    }
}
