<?php

namespace App\Policies;

use App\Models\Conference;
use App\Models\Review;
use App\Models\Submission;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

class ReviewPolicy
{
    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('submission:review');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Review $review): bool
    {
        if ($user->id === $review->reviewer_id) {
            return true;
        }

        if ($user->id === $review->submission->user_id) {
            return true;
        }

        return $this->hasConferencePermission($user, $review->submission->conference, 'conference:manage');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user, Submission $submission): bool
    {
        return $this->hasConferencePermission($user, $submission->conference, 'submission:review');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Review $review): bool
    {
        return $user->id === $review->reviewer_id;
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Review $review): bool
    {
        return $user->id === $review->reviewer_id;
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Review $review): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Review $review): bool
    {
        return false;
    }

    /**
     * Helper method to check if the user has a specific permission for the conference
     * associated with the review.
     */
    private function hasConferencePermission(User $user, Conference $conference, string $permission): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $hasPermission = $user->hasPermissionTo($permission);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $hasPermission;
    }
}
