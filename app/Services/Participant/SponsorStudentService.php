<?php

namespace App\Services\Participant;

use App\Models\AuditLog;
use App\Models\SponsorStudent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SponsorStudentService
{
    public function add(User $sponsor, User $student, ?string $relationshipType, User $actor): SponsorStudent
    {
        return DB::transaction(function () use ($sponsor, $student, $relationshipType, $actor) {
            $sponsorStudent = SponsorStudent::query()->create([
                'sponsor_id' => $sponsor->id,
                'student_id' => $student->id,
                'relationship_type' => $relationshipType,
                'status' => 'active',
                'start_date' => now()->toDateString(),
            ]);

            $this->logAction($actor, 'sponsor_student.added', $sponsorStudent, null);

            return $sponsorStudent;
        });
    }

    /**
     * Logical removal: preserves the historical relationship for future payment references.
     */
    public function remove(SponsorStudent $sponsorStudent, User $actor): void
    {
        DB::transaction(function () use ($sponsorStudent, $actor): void {
            $original = $sponsorStudent->getAttributes();

            $sponsorStudent->update([
                'status' => 'inactive',
                'end_date' => now()->toDateString(),
            ]);

            $this->logAction($actor, 'sponsor_student.removed', $sponsorStudent, $original);
        });
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     */
    private function logAction(User $actor, string $action, SponsorStudent $sponsorStudent, ?array $oldValues): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'entity_type' => SponsorStudent::class,
            'entity_id' => $sponsorStudent->id,
            'old_values' => $oldValues,
            'new_values' => $sponsorStudent->getAttributes(),
        ]);
    }
}
