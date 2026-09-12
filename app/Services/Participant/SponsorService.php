<?php

namespace App\Services\Participant;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class SponsorService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            $sponsor = User::query()->create([
                ...$data,
                'user_type' => 'sponsor',
                'status' => 'active',
                'password' => null,
            ]);

            $this->logAction($actor, 'sponsor.created', $sponsor, null);

            return $sponsor;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $sponsor, array $data, User $actor): User
    {
        $original = $sponsor->getAttributes();

        $sponsor->update($data);

        $this->logAction($actor, 'sponsor.updated', $sponsor, $original);

        return $sponsor;
    }

    /**
     * Soft-deletes the account, or deactivates it first if the sponsor still
     * has active class participation, preserving all historical records.
     */
    public function delete(User $sponsor, User $actor): void
    {
        DB::transaction(function () use ($sponsor, $actor): void {
            $original = $sponsor->getAttributes();

            if ($sponsor->classParticipants()->where('status', 'active')->exists()) {
                $sponsor->update(['status' => 'inactive']);
            }

            $sponsor->delete();

            $this->logAction($actor, 'sponsor.deleted', $sponsor, $original);
        });
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     */
    private function logAction(User $actor, string $action, User $sponsor, ?array $oldValues): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'entity_type' => User::class,
            'entity_id' => $sponsor->id,
            'old_values' => $oldValues ? collect($oldValues)->except(['password'])->all() : null,
            'new_values' => collect($sponsor->getAttributes())->except(['password'])->all(),
        ]);
    }
}
