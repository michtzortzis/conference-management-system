<?php

use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $response = $this->get(route('dashboard'));
    $response->assertStatus(200);
});

test('an author sees only their own data and never global aggregates', function () {
    $author = User::factory()->create();
    $otherAuthor = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $author->assignRole('author');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    $track = Track::create([
        'conference_id' => $conference->id,
        'name' => 'Main Track',
    ]);

    Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => $author->id,
        'title' => 'My Paper',
        'abstract' => 'Mine',
        'status' => SubmissionStatus::Submitted,
    ]);

    Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => $otherAuthor->id,
        'title' => 'Someone Else Paper',
        'abstract' => 'Theirs',
        'status' => SubmissionStatus::Submitted,
    ]);

    $response = $this->actingAs($author)->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('stats.my_submissions', 1)
        ->where('stats.pending_reviews', 0)
        ->where('stats.my_conferences', 0)
        ->where('globalCounts', null)
        ->has('recentSubmissions', 1)
    );
});

test('a committee member sees their conference submissions and pending review workload', function () {
    $committee = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Committee Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $committee->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $track = Track::create([
        'conference_id' => $conference->id,
        'name' => 'Main Track',
    ]);

    $submission = Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => User::factory()->create()->id,
        'title' => 'Conference Paper',
        'abstract' => 'For review',
        'status' => SubmissionStatus::UnderReview,
    ]);

    ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $committee->id,
        'status' => ReviewAssignmentStatus::Pending,
        'assigned_at' => now(),
    ]);

    $response = $this->actingAs($committee)->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('stats.pending_reviews', 1)
        ->where('stats.my_conferences', 1)
        ->where('globalCounts', null)
        ->has('recentSubmissions', 1)
        ->where('recentSubmissions.0.title', 'Conference Paper')
    );
});

test('a committee member never sees another author\'s draft submission', function () {
    $committee = User::factory()->create();
    $author = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Committee Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $committee->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $track = Track::create([
        'conference_id' => $conference->id,
        'name' => 'Main Track',
    ]);

    Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => $author->id,
        'title' => 'Draft Paper',
        'abstract' => 'Still a draft',
        'status' => SubmissionStatus::Draft,
    ]);

    $response = $this->actingAs($committee)->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('stats.my_conferences', 1)
        ->has('recentSubmissions', 0)
    );
});

test('an admin sees global aggregate counts', function () {
    $admin = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $admin->assignRole('admin');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    Conference::create([
        'title' => 'Admin Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    $response = $this->actingAs($admin)->get(route('dashboard'));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Dashboard')
        ->where('globalCounts.conferences', 1)
        ->where('globalCounts.submissions', 0)
    );
});
