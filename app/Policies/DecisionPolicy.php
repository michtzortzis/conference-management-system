<?php

namespace App\Policies;

use App\Models\Conference;
use App\Models\Decision;
use App\Models\User;
use Spatie\Permission\PermissionRegistrar;

class DecisionPolicy
{
    public function store(User $user, Decision $decision): bool
    {
        return $decision->submission->conference && $this->hasConferenceRole(
            $user,
            $decision->submission->conference,
            'chair'
        );
    }

    private function hasConferenceRole(User $user, Conference $conference, string $role): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $hasRole = $user->hasRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $hasRole;
    }
}
