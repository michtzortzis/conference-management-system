<?php

namespace App\Policies;

use App\Enums\ReviewAssignmentStatus;
use App\Models\Conference;
use App\Models\ReviewAssignment;
use App\Models\User;
use App\Services\ConflictOfInterestService;
use Spatie\Permission\PermissionRegistrar;

class ReviewAssignmentPolicy
{
    public function accept(User $user, ReviewAssignment $assignment): bool
    {
        if ($user->id !== $assignment->reviewer_id) {
            return false;
        }

        // A co-author must never review their own paper, even if an assignment
        // slipped through the invalidation path (e.g. accepted in a race).
        if ($assignment->submission->coAuthors()->where('user_id', $user->id)->exists()) {
            return false;
        }

        // A *declared* conflict is authoritative (AD-004): the reviewer stated it,
        // so accepting would put a conflicted review into the record. This closes
        // D-8.1 — the label in Fig 3.8 always claimed it; nothing enforced it.
        if (app(ConflictOfInterestService::class)->hasDeclared($assignment->submission, $user)) {
            return false;
        }

        return $assignment->status === ReviewAssignmentStatus::Pending;
    }

    public function decline(User $user, ReviewAssignment $assignment): bool
    {
        if ($user->id !== $assignment->reviewer_id) {
            return false;
        }

        return $assignment->status === ReviewAssignmentStatus::Pending;
    }

    public function selfDeclare(User $user, ReviewAssignment $assignment): bool
    {
        if ($user->id !== $assignment->reviewer_id) {
            return false;
        }

        return in_array($assignment->status, [
            ReviewAssignmentStatus::Pending,
            ReviewAssignmentStatus::Accepted,
        ], true);
    }

    public function create(User $user, Conference $conference): bool
    {
        return $this->hasConferenceRole($user, $conference, 'chair');
    }

    public function manage(User $user, ReviewAssignment $assignment): bool
    {
        $conference = $assignment->submission->conference;

        return $this->hasConferenceRole($user, $conference, 'chair');
    }

    private function hasConferenceRole(User $user, Conference $conference, string $role): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $hasRole = $user->hasRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $hasRole;
    }
}
