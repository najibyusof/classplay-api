<?php

namespace App\Services\Organization;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class OrganizationService
{
    public function create(array $data, User $creator): Organization
    {
        return DB::transaction(function () use ($data, $creator) {
            $organization = Organization::query()->create([
                ...$data,
                'created_by' => $creator->id,
            ]);

            OrganizationAdmin::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $creator->id,
                'is_primary' => true,
                'status' => 'active',
            ]);

            $this->logAction($creator, 'organization.created', $organization, null, $organization->toArray());

            return $organization;
        });
    }

    public function update(Organization $organization, array $data, User $actor): Organization
    {
        $original = $organization->getAttributes();

        $organization->update($data);

        $this->logAction($actor, 'organization.updated', $organization, $original, $organization->getChanges());

        return $organization;
    }

    /**
     * Soft-delete the organization.
     *
     * Future rule (Phase 9+): once classes exist with lifecycle state, this
     * should also reject deletion when active (non-completed) classes exist,
     * not merely any class record.
     */
    public function delete(Organization $organization, User $actor): void
    {
        if ($organization->classes()->exists()) {
            throw new RuntimeException('Cannot delete an organization that still has classes.');
        }

        $original = $organization->getAttributes();

        $organization->delete();

        $this->logAction($actor, 'organization.deleted', $organization, $original, null);
    }

    public function addAdmin(Organization $organization, User $targetUser, bool $isPrimary, User $actor): OrganizationAdmin
    {
        return DB::transaction(function () use ($organization, $targetUser, $isPrimary, $actor) {
            if ($isPrimary) {
                $organization->organizationAdmins()->update(['is_primary' => false]);
            }

            $admin = $organization->organizationAdmins()->create([
                'user_id' => $targetUser->id,
                'is_primary' => $isPrimary,
                'status' => 'active',
            ]);

            $this->logAction($actor, 'organization.admin_added', $organization, null, $admin->toArray());

            return $admin;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function updateAdmin(Organization $organization, OrganizationAdmin $admin, array $data, User $actor): OrganizationAdmin
    {
        return DB::transaction(function () use ($organization, $admin, $data, $actor) {
            $original = $admin->getAttributes();

            if (($data['is_primary'] ?? false) === true) {
                $organization->organizationAdmins()
                    ->where('id', '!=', $admin->id)
                    ->update(['is_primary' => false]);
            }

            $admin->update($data);

            $this->logAction($actor, 'organization.admin_updated', $organization, $original, $admin->getChanges());

            return $admin;
        });
    }

    public function removeAdmin(Organization $organization, OrganizationAdmin $admin, User $actor): void
    {
        $activeAdminCount = $organization->organizationAdmins()->where('status', 'active')->count();

        if ($admin->status === 'active' && $activeAdminCount <= 1) {
            throw new RuntimeException('Cannot remove the last administrator of an organization.');
        }

        if ($admin->is_primary) {
            $hasAnotherAdmin = $organization->organizationAdmins()
                ->where('id', '!=', $admin->id)
                ->where('status', 'active')
                ->exists();

            if (! $hasAnotherAdmin) {
                throw new RuntimeException('Cannot remove the primary administrator without another administrator in place.');
            }
        }

        $original = $admin->getAttributes();

        $admin->delete();

        $this->logAction($actor, 'organization.admin_removed', $organization, $original, null);
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function logAction(User $actor, string $action, Organization $organization, ?array $oldValues, ?array $newValues): void
    {
        AuditLog::query()->create([
            'user_id' => $actor->id,
            'action' => $action,
            'entity_type' => Organization::class,
            'entity_id' => $organization->id,
            'old_values' => $oldValues,
            'new_values' => $newValues,
        ]);
    }
}
