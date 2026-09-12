<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationAdminRequest extends FormRequest
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
        $organization = $this->route('organization');

        return [
            'user_id' => [
                'required',
                'integer',
                Rule::exists('users', 'id')->where('user_type', 'admin'),
                Rule::unique('organization_admins', 'user_id')->where('organization_id', $organization->id),
            ],
            'is_primary' => ['nullable', 'boolean'],
            'status' => ['nullable', 'in:active,inactive'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'user_id.exists' => 'The selected user must be an existing admin-type user.',
            'user_id.unique' => 'This user is already an administrator of this organization.',
        ];
    }
}
