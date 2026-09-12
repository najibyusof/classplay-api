<?php

namespace App\Services\Participant;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class StudentService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $student = User::query()->create([
                ...$data,
                'user_type' => 'student',
                'status' => 'active',
                'password' => null,
            ]);

            $this->logAction($actor, 'student.created', $student, null);

            return $student;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $student, array $data, User $actor): User
    {
        $original = $student->getAttributes();

        $student->update($data);

        $this->logAction($actor, 'student.updated', $student, $original);

        return $student;
    }

    /**
     * Soft-deletes the account, or deactivates it first if the student still
     * has active class participation, preserving all historical records.
     */
    public function delete(User $student, User $actor): void
    {
        DB::transaction(function () use ($student, $actor): void {
            $original = $student->getAttributes();

            if ($student->classParticipants()->where('status', 'active')->exists()) {
                $student->update(['status' => 'inactive']);
            }

            $student->delete();

            $this->logAction($actor, 'student.deleted', $student, $original);
        });
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     */
    private function logAction(User $actor, string $action, User $student, ?array $oldValues): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'entity_type' => User::class,
            'entity_id' => $student->id,
            'old_values' => $oldValues ? collect($oldValues)->except(['password'])->all() : null,
            'new_values' => collect($student->getAttributes())->except(['password'])->all(),
        ]);
    }
}
