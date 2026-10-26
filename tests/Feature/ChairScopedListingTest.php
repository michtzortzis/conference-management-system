<?php

use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeChairScopedConference(array $attributes = []): Conference
{
    return Conference::create(array_merge([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ], $attributes));
}

function makeChairScopedSubmission(Conference $conference, User $author): Submission
{
    $track = Track::create([
        'conference_id' => $conference->id,
        'name' => 'Main Track',
        'submission_open_at' => now()->subDays(10)->toDateString(),
        'submission_close_at' => now()->addDays(30)->toDateString(),
    ]);

    return Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => $author->id,
        'title' => 'Scoped Paper',
        'abstract' => 'Test abstract',
        'status' => SubmissionStatus::Submitted,
    ]);
}

function assignScopedChairRole(User $user, Conference $conference): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $user->assignRole('chair');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
}

test('submissions index lists submissions for a chair scoped to a later conference', function () {
    $author = User::factory()->create();
    $chair = User::factory()->create();

    // First conference (iterated first) where the chair has NO role.
    makeChairScopedConference();

    // Second conference (iterated after) where the chair holds the role.
    $conference = makeChairScopedConference();
    $submission = makeChairScopedSubmission($conference, $author);
    assignScopedChairRole($chair, $conference);

    $response = $this->actingAs($chair)->get(route('submissions.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('submissions', 1)
        ->where('submissions.0.id', $submission->id)
    );
});

test('assignments overview lists assignments for a chair scoped to a later conference', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();
    $chair = User::factory()->create();

    // First conference (iterated first) where the chair has NO role.
    makeChairScopedConference();

    // Second conference (iterated after) where the chair holds the role.
    $conference = makeChairScopedConference();
    $submission = makeChairScopedSubmission($conference, $author);
    assignScopedChairRole($chair, $conference);

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Pending,
        'assigned_at' => now(),
    ]);

    $response = $this->actingAs($chair)->get(route('assignments.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('assignments', 1)
        ->where('assignments.0.id', $assignment->id)
    );
});
