<?php

namespace App\Policies;

use App\Models\SponsorStudent;
use App\Models\User;

class SponsorStudentPolicy
{
    public function viewAny(User $user, User $sponsor): bool
    {
        return $user->id === $sponsor->id
            || ($user->hasPermission('participant.view') && $user->canManageParticipantUser($sponsor));
    }

    public function view(User $user, SponsorStudent $sponsorStudent): bool
    {
        return $user->id === $sponsorStudent->sponsor_id
            || $user->id === $sponsorStudent->student_id
            || ($user->hasPermission('participant.view') && (
                $user->canManageParticipantUser($sponsorStudent->sponsor)
                || $user->canManageParticipantUser($sponsorStudent->student)
            ));
    }

    public function create(User $user, User $sponsor, User $student): bool
    {
        return $user->hasPermission('participant.create')
            && ($user->canManageParticipantUser($sponsor) || $user->canManageParticipantUser($student));
    }

    public function delete(User $user, SponsorStudent $sponsorStudent): bool
    {
        return $user->hasPermission('participant.delete') && (
            $user->canManageParticipantUser($sponsorStudent->sponsor)
            || $user->canManageParticipantUser($sponsorStudent->student)
        );
    }
}
