<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class StudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'status' => $this->status,
            'phone_verified_at' => $this->phone_verified_at,
            'last_login_at' => $this->last_login_at,
            'classes' => $this->when(
                $this->relationLoaded('classParticipants'),
                fn () => $this->visibleClasses($request)
            ),
        ];
    }

    /**
     * Only reveal class participation within organizations the requesting admin manages.
     *
     * @return array<int, array<string, mixed>>
     */
    private function visibleClasses(Request $request): array
    {
        $admin = $request->user();
        $organizationIds = $admin
            ? $admin->organizationAdmins()->where('status', 'active')->pluck('organization_id')
            : collect();

        return $this->classParticipants
            ->filter(fn ($participant) => $organizationIds->contains($participant->classModel->organization_id))
            ->map(fn ($participant) => [
                'class_id' => $participant->class_id,
                'class_name' => $participant->classModel->name,
                'participant_status' => $participant->status,
            ])
            ->values()
            ->all();
    }
}
