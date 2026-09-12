<?php

namespace App\Http\Requests\Participant;

use App\Services\PhoneNumberNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSponsorRequest extends FormRequest
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
        $sponsor = $this->route('sponsor');

        return [
            'name' => ['sometimes', 'string', 'max:150'],
            'phone' => ['sometimes', 'string', 'regex:/^\+60[0-9]{7,11}$/', 'unique:users,phone,'.$sponsor->id],
            'email' => ['nullable', 'email', 'max:150'],
            'status' => ['sometimes', 'in:active,inactive,suspended'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->filled('phone')) {
            $this->merge(['phone' => PhoneNumberNormalizer::normalize((string) $this->input('phone'))]);
        }
    }
}
