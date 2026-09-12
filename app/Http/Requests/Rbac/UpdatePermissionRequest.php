<?php

namespace App\Http\Requests\Rbac;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePermissionRequest extends FormRequest
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
        $permission = $this->route('permission');

        return [
            'name' => ['sometimes', 'string', 'max:150', 'unique:permissions,name,'.$permission->id],
            'description' => ['nullable', 'string', 'max:255'],
        ];
    }
}
