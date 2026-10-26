<?php

namespace App\Policies;

use App\Models\Conference;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

class ConferencePolicy
{
    private const GLOBAL_TEAM_ID = 0;

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(User $user, Conference $conference): bool
    {
        return true;
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(User $user): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(self::GLOBAL_TEAM_ID);

        try {
            return $user->hasPermissionTo('conference:create');
        } finally {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Conference $conference): bool
    {
        return $this->hasConferencePermission($user, $conference, 'conference:manage');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Conference $conference): bool
    {
        return $this->hasConferencePermission($user, $conference, 'conference:archive');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(User $user, Conference $conference): bool
    {
        return false;
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(User $user, Conference $conference): bool
    {
        return false;
    }

    /**
     * Check if the user has a specific permission for the conference.
     */
    private function hasConferencePermission(User $user, Conference $conference, string $permission): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);

        try {
            return $user->hasPermissionTo($permission);
        } finally {
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
    }
}
