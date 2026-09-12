<?php

namespace App\Http\Resources;

use App\Models\ClassParticipant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ClassParticipant */
class ClassParticipantResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'class_id' => $this->class_id,
            'user' => new UserResource($this->whenLoaded('user')),
            'participant_type' => $this->participant_type,
            'status' => $this->status,
            'joined_at' => $this->joined_at,
            'left_at' => $this->left_at,
        ];
    }
}
