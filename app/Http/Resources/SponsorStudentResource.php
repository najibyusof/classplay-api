<?php

namespace App\Http\Resources;

use App\Models\SponsorStudent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin SponsorStudent */
class SponsorStudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sponsor' => new UserResource($this->whenLoaded('sponsor')),
            'student' => new UserResource($this->whenLoaded('student')),
            'relationship_type' => $this->relationship_type,
            'status' => $this->status,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
        ];
    }
}
