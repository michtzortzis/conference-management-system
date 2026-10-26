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

function inboxConference(string $title = 'Inbox Conference'): Conference
{
    return Conference::create([
        'title' => $title,
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);
}

function inboxSubmission(Conference $conference, User $author): Submission
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
        'title' => 'Inbox Paper',
        'abstract' => 'Test abstract',
        'status' => SubmissionStatus::Submitted,
    ]);
}

function inboxAssignRole(User $user, Conference $conference, string $role): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $user->assignRole($role);
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
    $user->unsetRelation('roles');
}

function inboxAssign(Submission $submission, User $reviewer, ReviewAssignmentStatus $status): ReviewAssignment
{
    return ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => $status,
        'assigned_at' => now(),
        'accepted_at' => $status === ReviewAssignmentStatus::Accepted ? now() : null,
        'declined_at' => $status === ReviewAssignmentStatus::Declined ? now() : null,
    ]);
}

test('a pending invitation lands in assignments and not in reviews', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    $conference = inboxConference();
    inboxAssignRole($reviewer, $conference, 'committee_member');
    $assignment = inboxAssign(inboxSubmission($conference, $author), $reviewer, ReviewAssignmentStatus::Pending);

    $this->actingAs($reviewer)
        ->get(route('assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Assignments/Index')
            ->has('reviewer_assignments', 1)
            ->where('reviewer_assignments.0.id', $assignment->id)
            ->where('reviewer_assignments.0.status', 'pending')
            ->where('assignments', [])
        );

    $this->actingAs($reviewer)
        ->get(route('reviews.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reviews/Index')
            ->where('assignments', [])
        );
});

test('an accepted assignment appears in reviews and stays in the assignments inbox', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    $conference = inboxConference();
    inboxAssignRole($reviewer, $conference, 'committee_member');
    $assignment = inboxAssign(inboxSubmission($conference, $author), $reviewer, ReviewAssignmentStatus::Accepted);

    $this->actingAs($reviewer)
        ->get(route('reviews.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reviews/Index')
            ->has('assignments', 1)
            ->where('assignments.0.id', $assignment->id)
            ->where('assignments.0.status', 'accepted')
        );

    $this->actingAs($reviewer)
        ->get(route('assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Assignments/Index')
            ->has('reviewer_assignments', 1)
            ->where('reviewer_assignments.0.id', $assignment->id)
        );
});

test('a declined assignment disappears from both tabs', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    $conference = inboxConference();
    inboxAssignRole($reviewer, $conference, 'committee_member');
    inboxAssign(inboxSubmission($conference, $author), $reviewer, ReviewAssignmentStatus::Declined);

    $this->actingAs($reviewer)
        ->get(route('assignments.index'))
        ->assertInertia(fn (Assert $page) => $page->where('reviewer_assignments', []));

    $this->actingAs($reviewer)
        ->get(route('reviews.index'))
        ->assertInertia(fn (Assert $page) => $page->where('assignments', []));
});

test('the reviewer inbox never lists another reviewer assignments', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();
    $other = User::factory()->create();

    $conference = inboxConference();
    $submission = inboxSubmission($conference, $author);
    inboxAssignRole($reviewer, $conference, 'committee_member');
    inboxAssignRole($other, $conference, 'committee_member');

    $mine = inboxAssign($submission, $reviewer, ReviewAssignmentStatus::Pending);
    inboxAssign($submission, $other, ReviewAssignmentStatus::Pending);

    $this->actingAs($reviewer)
        ->get(route('assignments.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('reviewer_assignments', 1)
            ->where('reviewer_assignments.0.id', $mine->id)
            ->where('assignments', [])
        );
});

test('the chair overview still lists only assignments from chaired conferences', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();
    $chair = User::factory()->create();

    $chaired = inboxConference('Chaired');
    $other = inboxConference('Other');
    inboxAssignRole($chair, $chaired, 'chair');
    inboxAssignRole($reviewer, $chaired, 'committee_member');

    $visible = inboxAssign(inboxSubmission($chaired, $author), $reviewer, ReviewAssignmentStatus::Pending);
    inboxAssign(inboxSubmission($other, $author), $reviewer, ReviewAssignmentStatus::Pending);

    $this->actingAs($chair)
        ->get(route('assignments.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Assignments/Index')
            ->has('assignments', 1)
            ->where('assignments.0.id', $visible->id)
            ->where('reviewer_assignments', [])
        );
});

test('accepting an invitation from the assignments page moves it to reviews', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    $conference = inboxConference();
    inboxAssignRole($reviewer, $conference, 'committee_member');
    $assignment = inboxAssign(inboxSubmission($conference, $author), $reviewer, ReviewAssignmentStatus::Pending);

    $this->actingAs($reviewer)
        ->post(route('reviews.accept', $assignment))
        ->assertRedirect()
        ->assertSessionHas('success');

    expect($assignment->fresh()->status)->toBe(ReviewAssignmentStatus::Accepted);

    $this->actingAs($reviewer)
        ->get(route('reviews.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('assignments', 1)
            ->where('assignments.0.status', 'accepted')
        );
});

test('a reviewer cannot accept an invitation assigned to someone else from the assignments page', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();
    $intruder = User::factory()->create();

    $conference = inboxConference();
    inboxAssignRole($reviewer, $conference, 'committee_member');
    inboxAssignRole($intruder, $conference, 'committee_member');
    $assignment = inboxAssign(inboxSubmission($conference, $author), $reviewer, ReviewAssignmentStatus::Pending);

    $this->actingAs($intruder)
        ->post(route('reviews.accept', $assignment))
        ->assertForbidden();

    expect($assignment->fresh()->status)->toBe(ReviewAssignmentStatus::Pending);
});
