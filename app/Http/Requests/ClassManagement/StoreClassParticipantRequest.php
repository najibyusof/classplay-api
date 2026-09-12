<?php

namespace App\Http\Requests\ClassManagement;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreClassParticipantRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'participant_type' => ['required', 'in:student,sponsor'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $user = User::query()->find($this->input('user_id'));

            if ($user && $user->user_type !== $this->input('participant_type')) {
                $validator->errors()->add(
                    'participant_type',
                    'The participant type must match the user\'s account type.'
                );
            }
        });
    }
}
