<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\User;

class OrganizationPolicy
{
    /**
     * Gates the admin-facing organization list (GET /admin/organizations).
     * Scoped to the ADMIN role since students/sponsors have no admin-management view of organizations.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasRole('ADMIN') && $user->hasPermission('organization.view');
    }

    public function view(User $user, Organization $organization): bool
    {
        return $user->hasPermission('organization.view') && $user->isOrganizationAdmin($organization->id);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('organization.create');
    }

    public function update(User $user, Organization $organization): bool
    {
        return $user->hasPermission('organization.update') && $user->isOrganizationAdmin($organization->id);
    }

    public function delete(User $user, Organization $organization): bool
    {
        return $user->hasPermission('organization.delete') && $user->isOrganizationAdmin($organization->id);
    }
}
