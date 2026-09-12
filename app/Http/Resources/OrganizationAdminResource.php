<?php

namespace App\Http\Resources;

use App\Models\OrganizationAdmin;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin OrganizationAdmin */
class OrganizationAdminResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->user->id,
            'name' => $this->user->name,
            'phone' => $this->user->phone,
            'email' => $this->user->email,
            'is_primary' => $this->is_primary,
            'status' => $this->status,
        ];
    }
}
