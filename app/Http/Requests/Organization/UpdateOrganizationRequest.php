<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class UpdateOrganizationRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:200'],
            'code' => ['nullable', 'string', 'max:50', 'unique:organizations,code,'.$organization->id],
            'description' => ['nullable', 'string'],
            'logo_path' => ['nullable', 'string', 'max:500'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
