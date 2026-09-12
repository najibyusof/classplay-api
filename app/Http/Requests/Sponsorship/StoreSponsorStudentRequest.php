<?php

namespace App\Http\Requests\Sponsorship;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSponsorStudentRequest extends FormRequest
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
            'student_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('user_type', 'student'),
                Rule::unique('sponsor_students', 'student_id')->where('sponsor_id', $sponsor->id),
            ],
            'relationship_type' => ['nullable', 'string', 'max:100'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'student_id.exists' => 'The selected user must be an existing student-type user.',
            'student_id.unique' => 'This student is already linked to this sponsor.',
        ];
    }
}
