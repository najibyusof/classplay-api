<?php

namespace App\Services\Participant;

use App\Models\AuditLog;
use App\Models\ClassModel;
use App\Models\ClassParticipant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ParticipantService
{
    public function add(ClassModel $classModel, User $participantUser, string $participantType, User $actor): ClassParticipant
    {
        return DB::transaction(function () use ($classModel, $participantUser, $participantType, $actor) {
            $existing = $classModel->participants()->where('user_id', $participantUser->id)->first();

            if ($existing) {
                if ($existing->status === 'active') {
                    throw new RuntimeException('This user is already an active participant in the class.');
                }

                // Reuse the existing row (unique(class_id, user_id) prevents a second insert).
                $existing->update([
                    'participant_type' => $participantType,
                    'status' => 'active',
                    'joined_at' => now(),
                    'left_at' => null,
                ]);

                $this->logAction($actor, 'participant.added', $existing);

                return $existing;
            }

            $participant = $classModel->participants()->create([
                'user_id' => $participantUser->id,
                'participant_type' => $participantType,
                'status' => 'active',
                'joined_at' => now(),
            ]);

            $this->logAction($actor, 'participant.added', $participant);

            return $participant;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateStatus(ClassParticipant $participant, array $data, User $actor): ClassParticipant
    {
        return DB::transaction(function () use ($participant, $data, $actor) {
            $original = $participant->getAttributes();

            $status = $data['status'];

            $updates = ['status' => $status];

            if ($status === 'removed') {
                $updates['left_at'] = now();
            } elseif ($status === 'active' && $participant->status !== 'active') {
                $updates['joined_at'] = now();
                $updates['left_at'] = null;
            }

            $participant->update($updates);

            $this->logAction($actor, 'participant.updated', $participant, $original);

            return $participant;
        });
    }

    /**
     * Logical removal: preserves the historical record for any future payment references.
     */
    public function remove(ClassParticipant $participant, User $actor): void
    {
        $original = $participant->getAttributes();

        $participant->update([
            'status' => 'removed',
            'left_at' => now(),
        ]);

        $this->logAction($actor, 'participant.removed', $participant, $original);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     */
    private function logAction(User $actor, string $action, ClassParticipant $participant, ?array $oldValues = null): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'entity_type' => ClassParticipant::class,
            'entity_id' => $participant->id,
            'old_values' => $oldValues,
            'new_values' => $participant->getAttributes(),
        ]);
    }
}
