<?php

namespace Database\Seeders;

use App\Models\Conference;
use App\Models\Field;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

class TestUserSeeder extends Seeder
{
    private const GLOBAL_TEAM_ID = 0;

    public function assignConferenceRole(User $user, string $role, int $conferenceId): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($conferenceId);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    public function assignGlobalRole(User $user, string $role): void
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(self::GLOBAL_TEAM_ID);
        $user->assignRole($role);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    }

    /**
     * Create a demo account that can be logged straight into.
     *
     * Every route in routes/web.php carries the `verified` middleware, so an
     * unverified demo account gets a successful login and then a 403 on the
     * whole application. Verification is set through markEmailAsVerified()
     * because the column is deliberately not mass assignable.
     */
    private function createUser(string $firstName, string $lastName, string $email): User
    {
        $user = User::create([
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'password' => Hash::make('password'),
        ]);

        $user->markEmailAsVerified();

        return $user;
    }

    public function createTestConference(string $title, string $description, string $startDate, string $endDate): Conference
    {
        $conference = Conference::firstOrCreate(
            ['title' => $title],
            [
                'description' => $description,
                'start_date' => $startDate,
                'end_date' => $endDate,
            ]
        );

        if ($conference->tracks()->count() === 0) {
            Track::create([
                'conference_id' => $conference->id,
                'name' => 'Machine Learning',
                'description' => 'ML and AI track',
                'submission_open_at' => now()->subDays(30),
                'submission_close_at' => now()->addDays(30),
            ]);

            Track::create([
                'conference_id' => $conference->id,
                'name' => 'Data Science',
                'description' => 'Data science and analytics track',
                'submission_open_at' => now()->subDays(30),
                'submission_close_at' => now()->addDays(30),
            ]);
        }

        if ($conference->fields()->count() === 0) {
            $field1 = Field::firstOrCreate(['name' => 'Machine Learning']);
            $field2 = Field::firstOrCreate(['name' => 'Data Science']);
            $conference->fields()->sync([$field1->id, $field2->id]);
        }

        return $conference;
    }

    public function run(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $adminUser = User::where('email', 'admin@test.com')->first();
        if (! $adminUser) {
            $adminUser = $this->createUser('Admin', 'User', 'admin@test.com');
        } elseif (! $adminUser->hasVerifiedEmail()) {
            // Kept across re-seeds, so a database seeded before demo accounts
            // were verified still ends up with a usable admin.
            $adminUser->markEmailAsVerified();
        }
        $this->assignGlobalRole($adminUser, 'admin');

        $usersToDelete = User::where('email', '!=', 'admin@test.com')->get();
        foreach ($usersToDelete as $user) {
            $user->affiliations()->detach();
            $user->expertiseAreas()->detach();
            $user->roles()->detach();
            $user->delete();
        }

        $organizer1 = $this->createUser('Organizer', 'One', 'organizer1@test.com');
        $organizer2 = $this->createUser('Organizer', 'Two', 'organizer2@test.com');
        $this->assignGlobalRole($organizer1, 'organizer');
        $this->assignGlobalRole($organizer2, 'organizer');

        $chair1 = $this->createUser('Chair', 'One', 'chair1@test.com');
        $chair2 = $this->createUser('Chair', 'Two', 'chair2@test.com');
        $chair3 = $this->createUser('Chair', 'Three', 'chair3@test.com');

        $committee1 = $this->createUser('Committee', 'One', 'committee1@test.com');
        $committee2 = $this->createUser('Committee', 'Two', 'committee2@test.com');
        $committee3 = $this->createUser('Committee', 'Three', 'committee3@test.com');
        $committee4 = $this->createUser('Committee', 'Four', 'committee4@test.com');
        $committee5 = $this->createUser('Committee', 'Five', 'committee5@test.com');

        $author1 = $this->createUser('Author', 'One', 'author1@test.com');
        $author2 = $this->createUser('Author', 'Two', 'author2@test.com');
        $author3 = $this->createUser('Author', 'Three', 'author3@test.com');
        $author4 = $this->createUser('Author', 'Four', 'author4@test.com');
        $author5 = $this->createUser('Author', 'Five', 'author5@test.com');

        foreach ([$author1, $author2, $author3, $author4, $author5] as $author) {
            $this->assignGlobalRole($author, 'author');
        }

        $conference1 = $this->createTestConference(
            'AI Summit 2026',
            'Conference on AI and Machine Learning',
            '2026-06-01',
            '2026-06-03'
        );

        $conference2 = $this->createTestConference(
            'Data Science Congress',
            'Data science and analytics conference',
            '2026-09-01',
            '2026-09-03'
        );

        $this->assignConferenceRole($chair1, 'chair', $conference1->id);
        $this->assignConferenceRole($chair2, 'chair', $conference2->id);
        $this->assignConferenceRole($chair3, 'chair', $conference1->id);

        $this->assignConferenceRole($committee1, 'committee_member', $conference1->id);
        $this->assignConferenceRole($committee2, 'committee_member', $conference1->id);
        $this->assignConferenceRole($committee3, 'committee_member', $conference1->id);
        $this->assignConferenceRole($committee4, 'committee_member', $conference2->id);
        $this->assignConferenceRole($committee5, 'committee_member', $conference2->id);

        $conference1Admin = $this->createUser('Conference', 'Admin 1', 'confadmin1@test.com');
        $this->assignGlobalRole($conference1Admin, 'organizer');
        $this->assignConferenceRole($conference1Admin, 'chair', $conference1->id);
    }
}
