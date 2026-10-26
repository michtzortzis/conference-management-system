<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $globalOrganizerPermissions = [
            'conference:create',
        ];

        // Define permissions used by the conference management system.
        // Naming is intentionally human-readable; application code can check
        // these permission strings when enforcing business rules (e.g. only
        // allow paper submission when a conference is open).
        // System-wide permissions
        $systemPermissions = [
            'user:manage',
            'user:invite',
            'role:manage',
            'permission:manage',
        ];

        // Conference management permissions
        $conferencePermissions = [
            'conference:create',
            'conference:archive',
            'conference:manage',
            'committee:manage',
        ];

        // Submission management permissions
        $submissionPermissions = [
            'submission:submit',
            'submission:edit',
            'submission:review',
            'submission:publish_decision',
        ];

        // Merge all permissions into a single array for seeding
        $permissions = array_merge(
            $systemPermissions,
            $conferencePermissions,
            $submissionPermissions
        );

        foreach ($permissions as $perm) {
            Permission::firstOrCreate([
                'name' => $perm,
                'guard_name' => 'web',
            ]);
        }

        // Roles
        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $organizer = Role::firstOrCreate(['name' => 'organizer', 'guard_name' => 'web']);
        $chair = Role::firstOrCreate(['name' => 'chair', 'guard_name' => 'web']);
        $committeeMember = Role::firstOrCreate(['name' => 'committee_member', 'guard_name' => 'web']);
        $author = Role::firstOrCreate(['name' => 'author', 'guard_name' => 'web']);

        // Give admin all permissions
        $admin->syncPermissions(Permission::all());

        // Organizer: can create conferences globally, but does not manage all conferences.
        $organizer->syncPermissions(
            Permission::whereIn('name', $globalOrganizerPermissions)->get()
        );

        // Chair: manages a specific conference once assigned in that conference team.
        $chairPerms = [
            'conference:manage',
            'conference:archive',
            'committee:manage',
            'submission:publish_decision',
        ];
        $chair->syncPermissions(Permission::whereIn('name', $chairPerms)->get());

        // Committee member / reviewer: create and submit reviews, review assigned submissions
        $committeePerms = [
            'submission:review',
        ];
        $committeeMember->syncPermissions(Permission::whereIn('name', $committeePerms)->get());

        // Author: submit and edit their own submissions
        $authorPerms = [
            'submission:submit',
            'submission:edit',
        ];
        $author->syncPermissions(Permission::whereIn('name', $authorPerms)->get());
    }
}
