<?php

namespace App\Policies;

use App\Enums\Phase;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

class SubmissionPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user, Conference $conference): bool
    {
        return $this->hasConferenceRole($user, $conference, 'chair');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Submission $submission): bool
    {
        if ($user->id === $submission->user_id) {
            return true;
        }

        if ($submission->coAuthors()->where('user_id', $user->id)->exists()) {
            return true;
        }

        if (
            $submission->assignments()
                ->where('reviewer_id', $user->id)
                ->where('status', ReviewAssignmentStatus::Accepted)
                ->exists()
        ) {
            return true;
        }

        return $submission->conference && $this->hasConferenceRole($user, $submission->conference, 'chair');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, ?Conference $conference = null): bool
    {
        if (! $conference) {
            return false;
        }

        $hasSubmitPermission = $this->hasGlobalRole($user, 'author') || $this->hasConferenceRole($user, $conference, 'chair');

        if (! $hasSubmitPermission) {
            return false;
        }

        $now = now();
        foreach ($conference->tracks as $track) {
            $openAt = $track->phaseOpensAt(Phase::Submission);
            $closeAt = $track->phaseClosesAt(Phase::Submission);

            if (($openAt === null || $now >= $openAt) && ($closeAt === null || $now <= $closeAt)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Submission $submission): bool
    {
        if ($submission->conference && $this->hasConferenceRole($user, $submission->conference, 'chair')) {
            return true;
        }

        if ($user->id === $submission->user_id) {
            return in_array($submission->status, [SubmissionStatus::Draft, SubmissionStatus::Submitted]);
        }

        return false;
    }

    /**
     * Determine whether the user can submit the model for review.
     *
     * A draft with no file cannot enter review: the `⟨CFP open, ≥1 file⟩` guard of
     * Fig 3.7 is enforced here, in the policy the figure draws it in, so every
     * entry point (route, seeder, future job) meets one predicate (D-7.1).
     */
    public function submit(User $user, Submission $submission): bool
    {
        if ($user->id !== $submission->user_id) {
            return false;
        }

        if ($submission->status !== SubmissionStatus::Draft) {
            return false;
        }

        // `files()` is the only reliable count: uploads are anonymous id slugs.
        return $submission->files()->exists();
    }

    /**
     * Determine whether the user can move the submission into review.
     *
     * Chair-only: global admin is handled by Gate::before (team_id = 0), so no
     * global-role check here — one before the team-scoped check is the role-cache
     * trap recorded in .ai/rules/controllers.md.
     */
    public function startReview(User $user, Submission $submission): bool
    {
        if (! $submission->conference || ! $this->hasConferenceRole($user, $submission->conference, 'chair')) {
            return false;
        }

        return $submission->status === SubmissionStatus::Submitted;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Submission $submission): bool
    {
        if ($submission->conference && $this->hasConferenceRole($user, $submission->conference, 'chair')) {
            return true;
        }

        if ($user->id === $submission->user_id) {
            return $submission->status === SubmissionStatus::Draft;
        }

        return false;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Submission $submission): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Submission $submission): bool
    {
        return false;
    }

    /**
     * Determine whether the user can upload files to the submission.
     */
    public function uploadFile(User $user, Submission $submission): bool
    {
        if ($user->id === $submission->user_id) {
            return true;
        }

        return $this->hasConferenceRole($user, $submission->conference, 'chair');
    }

    /**
     * Determine whether the user can delete a file from the submission.
     */
    public function deleteFile(User $user, Submission $submission): bool
    {
        if ($user->id === $submission->user_id) {
            return true;
        }

        return $this->hasConferenceRole($user, $submission->conference, 'chair');
    }

    /**
     * Helper method to check if the user has a specific role for the conference.
     */
    private function hasConferenceRole(User $user, Conference $conference, string $role): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $hasRole = $user->hasRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $hasRole;
    }

    /**
     * Helper method to check if the user has a specific global role.
     */
    private function hasGlobalRole(User $user, string $role): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $hasRole = $user->hasRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $hasRole;
    }
}
