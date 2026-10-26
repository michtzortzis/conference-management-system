<?php

namespace App\Policies;

use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

class SubmissionFilePolicy
{
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
     * Determine whether the user can download a submission file.
     */
    public function downloadFile(User $user, SubmissionFile $file): bool
    {
        if ($user->id === $file->submission->user_id) {
            return true;
        }

        return $this->hasConferenceRole($user, $file->submission->conference, 'chair');
    }

    /**
     * Determine whether the user can delete a submission file.
     */
    public function deleteFile(User $user, SubmissionFile $file): bool
    {
        if ($user->id === $file->submission->user_id) {
            return true;
        }

        return $this->hasConferenceRole($user, $file->submission->conference, 'chair');
    }

    /**
     * Helper method to check if the user has a specific role for a conference.
     */
    private function hasConferenceRole(User $user, $conference, string $role): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $hasRole = $user->hasRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $hasRole;
    }
}
