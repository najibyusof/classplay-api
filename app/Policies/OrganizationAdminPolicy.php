<?php

namespace App\Policies;

use App\Models\Organization;
use App\Models\OrganizationAdmin;
use App\Models\User;

class OrganizationAdminPolicy
{
    public function viewAny(User $user, Organization $organization): bool
    {
        return $user->hasPermission('organization.manage_admins') && $user->isOrganizationAdmin($organization->id);
    }

    public function view(User $user, OrganizationAdmin $organizationAdmin): bool
    {
        return $user->hasPermission('organization.manage_admins') && $user->isOrganizationAdmin($organizationAdmin->organization_id);
    }

    public function create(User $user, Organization $organization): bool
    {
        return $user->hasPermission('organization.manage_admins') && $user->isOrganizationAdmin($organization->id);
    }

    public function update(User $user, OrganizationAdmin $organizationAdmin): bool
    {
        return $user->hasPermission('organization.manage_admins') && $user->isOrganizationAdmin($organizationAdmin->organization_id);
    }

    public function delete(User $user, OrganizationAdmin $organizationAdmin): bool
    {
        return $user->hasPermission('organization.manage_admins') && $user->isOrganizationAdmin($organizationAdmin->organization_id);
    }
}
