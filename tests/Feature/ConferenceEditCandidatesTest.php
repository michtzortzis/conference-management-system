<?php

use App\Models\Conference;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * D-4.3 — the former `GET /users` closure handed every account's id/first_name/
 * last_name/email to ANY verified user, with no policy in front of it. Its only
 * consumer was `resources/js/pages/Conferences/Edit.vue`, whose `fetch('/users')`
 * swallowed the 404 (a bare `if (res.ok)` over an empty `catch`), so both the chair
 * `<select>` and the committee `MultiSelectWithFields` rendered empty forever.
 *
 * The pool now ships as the `user_candidates` prop of `ConferenceController@edit`,
 * which `authorize('update')` gates via `ConferencePolicy@update`
 * (`conference:manage`) — the property `/users` never had. `update()` validates
 * `chair_user_id` and `committee_members.*` as plain `exists:users,id`, so the pool
 * must stay ALL users: narrowing it to current role-holders would break appointments.
 */
function editCandidatesConference(): Conference
{
    return Conference::create([
        'title' => 'Candidates Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(30)->toDateString(),
    ]);
}

function assignEditTeamRole(User $user, string $role, Conference $conference): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
}

function assignGlobalAuthorRole(?User $author = null): User
{
    $author = $author ?? User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $author->assignRole('author');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $author;
}

test('the chair receives the full appointment pool for the committee picker', function () {
    $conference = editCandidatesConference();

    $chair = User::factory()->create();
    assignEditTeamRole($chair, 'chair', $conference);

    $committee_member = User::factory()->create();
    assignEditTeamRole($committee_member, 'committee_member', $conference);

    // The whole point of an APPOINTMENT pool: an account holding no role in this
    // conference is still offered, so the chair can appoint them.
    $candidate = User::factory()->create([
        'first_name' => 'Zoe',
        'last_name' => 'Adams',
        'email' => 'zoe.adams@example.test',
    ]);

    $this->actingAs($chair)
        ->get(route('conferences.edit', $conference))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('conference.id', $conference->id)
            ->where('chair.id', $chair->id)
            ->where('committee_members', fn ($members) => $members->contains('id', $committee_member->id))
            ->where('user_candidates', fn ($users) => $users->isNotEmpty())
            ->where('user_candidates', fn ($users) => $users->contains('id', $candidate->id))
            ->where('user_candidates', fn ($users) => $users->contains('id', $chair->id))
        );
});

test('a candidate row exposes only the identity columns the pickers render', function () {
    $conference = editCandidatesConference();

    $chair = User::factory()->create();
    assignEditTeamRole($chair, 'chair', $conference);

    $candidate = User::factory()->create();
    assignGlobalAuthorRole($candidate);

    $this->actingAs($chair)
        ->get(route('conferences.edit', $conference))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('user_candidates', fn ($users) => $users->isNotEmpty())
            ->where('user_candidates', fn ($users) => $users->every(
                fn (array $row) => array_keys($row) === ['id', 'first_name', 'last_name', 'email'],
            ))
        );
});

test('a verified author without conference:manage cannot reach the edit page', function () {
    $conference = editCandidatesConference();
    $author = assignGlobalAuthorRole();

    $this->actingAs($author)
        ->get(route('conferences.edit', $conference))
        ->assertForbidden();
});

test('the deleted users endpoint is gone', function () {
    $this->actingAs(User::factory()->create())
        ->get('/users')
        ->assertNotFound();
});
