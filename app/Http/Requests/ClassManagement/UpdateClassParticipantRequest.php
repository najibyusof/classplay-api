<?php

namespace App\Http\Requests\ClassManagement;

use Illuminate\Foundation\Http\FormRequest;

class UpdateClassParticipantRequest extends FormRequest
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
            'status' => ['required', 'in:active,inactive,removed'],
        ];
    }
}
