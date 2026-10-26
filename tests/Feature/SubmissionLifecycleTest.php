<?php

use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Track;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('a submission travels the whole lifecycle through HTTP routes only', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();
    $reviewer = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    // Review and decision boundaries stay null, so both phases are unbounded and
    // no phase advance is needed to walk the lifecycle.
    $track = Track::create([
        'conference_id' => $conference->id,
        'name' => 'Main Track',
        'submission_open_at' => now()->subDay(),
        'submission_close_at' => now()->addDays(30),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $author->assignRole('author');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    $reviewer->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Hop 1: the author creates a draft through the store route.
    $this->actingAs($author)
        ->from(route('submissions.create'))
        ->post(route('submissions.store'), [
            'conference_id' => $conference->id,
            'track_id' => $track->id,
            'title' => 'Lifecycle Paper',
            'abstract' => 'Novel approach',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $submission = $conference->submissions()->sole();

    expect($submission->status)->toBe(SubmissionStatus::Draft);

    // Hop 2: the author submits it for review. Fig 3.7's `>=1 file` guard is enforced in
    // SubmissionPolicy::submit (D-7.1), so an empty draft cannot make this hop.
    attachFileToSubmission($submission);

    $this->actingAs($author)
        ->post(route('submissions.submit', $submission))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Submitted);

    // Hop 3: the chair moves it into review (SD-01).
    $this->actingAs($chair)
        ->post(route('submissions.start-review', $submission))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);

    // Hop 4: the chair assigns a reviewer. The status is already past submitted,
    // so the auto-promotion is a no-op here.
    $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $assignment = $submission->fresh()->assignments()->sole();

    expect($assignment->status)->toBe(ReviewAssignmentStatus::Pending);
    expect($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);

    // Hop 5: the reviewer accepts the assignment.
    $this->actingAs($reviewer)
        ->post(route('reviews.accept', $assignment))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($assignment->fresh()->status)->toBe(ReviewAssignmentStatus::Accepted);

    // Hop 6: the reviewer writes the review.
    $this->actingAs($reviewer)
        ->post(route('reviews.store'), [
            'submission_id' => $submission->id,
            'assignment_id' => $assignment->id,
            'recommendation' => 'accept',
            'comments' => 'Excellent paper',
            'grade' => 5,
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);

    // Hop 7: the chair records the acceptance decision.
    $this->actingAs($chair)
        ->post(route('submissions.decision.store', $submission), [
            'decision' => 'accepted',
            'rationale' => 'Strong consensus',
        ])
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Accepted);
});
