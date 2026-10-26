<?php

use App\Enums\ReviewAssignmentStatus;
use App\Enums\ReviewRecommendation;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('chair can assign multiple reviewers to a submission', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();
    $reviewer1 = User::factory()->create();
    $reviewer2 = User::factory()->create();
    $reviewer3 = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    $reviewer1->assignRole('committee_member');
    $reviewer2->assignRole('committee_member');
    $reviewer3->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Track with an OPEN review window: PhaseGuards (AD-012) derives legality
    // from the boundary pair, so a trackless submission cannot be staffed or reviewed.
    $track = openReviewTrack($conference);

    $submission = Submission::create([
        'track_id' => $track->id,
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Test Paper',
        'abstract' => 'Test abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer1->id,
        ])
        ->assertRedirect();

    $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer2->id,
        ])
        ->assertRedirect();

    $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer3->id,
        ])
        ->assertRedirect();

    expect($submission->fresh()->assignments()->count())->toBe(3);
});

test('each reviewer can independently accept and write a review', function () {
    $author = User::factory()->create();
    $reviewer1 = User::factory()->create();
    $reviewer2 = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $reviewer1->assignRole('committee_member');
    $reviewer2->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Track with an OPEN review window: PhaseGuards (AD-012) derives legality
    // from the boundary pair, so a trackless submission cannot be staffed or reviewed.
    $track = openReviewTrack($conference);

    $submission = Submission::create([
        'track_id' => $track->id,
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Test Paper',
        'abstract' => 'Test abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    $assignment1 = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer1->id,
        'status' => ReviewAssignmentStatus::Pending,
        'assigned_at' => now(),
    ]);

    $assignment2 = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer2->id,
        'status' => ReviewAssignmentStatus::Pending,
        'assigned_at' => now(),
    ]);

    $assignment1->update([
        'status' => ReviewAssignmentStatus::Accepted,
        'accepted_at' => now(),
    ]);

    $assignment2->update([
        'status' => ReviewAssignmentStatus::Accepted,
        'accepted_at' => now(),
    ]);

    $review1 = Review::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer1->id,
        'assignment_id' => $assignment1->id,
        'recommendation' => ReviewRecommendation::Accept,
        'comments' => 'Reviewer 1: Excellent paper',
    ]);

    $review2 = Review::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer2->id,
        'assignment_id' => $assignment2->id,
        'recommendation' => ReviewRecommendation::MinorRevision,
        'comments' => 'Reviewer 2: Good but needs minor changes',
    ]);

    expect($review1->recommendation)->toBe(ReviewRecommendation::Accept);
    expect($review2->recommendation)->toBe(ReviewRecommendation::MinorRevision);
    expect($submission->reviews()->count())->toBe(2);
    expect($submission->assignments()->count())->toBe(2);
});

test('chair can view all reviews on the submission show page', function () {
    $author = User::factory()->create();
    $chair = User::factory()->create();
    $reviewer1 = User::factory()->create();
    $reviewer2 = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    $reviewer1->assignRole('committee_member');
    $reviewer2->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Track with an OPEN review window: PhaseGuards (AD-012) derives legality
    // from the boundary pair, so a trackless submission cannot be staffed or reviewed.
    $track = openReviewTrack($conference);

    $submission = Submission::create([
        'track_id' => $track->id,
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Test Paper',
        'abstract' => 'Test abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    $assignment1 = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer1->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
        'accepted_at' => now(),
    ]);

    $assignment2 = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer2->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
        'accepted_at' => now(),
    ]);

    Review::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer1->id,
        'assignment_id' => $assignment1->id,
        'recommendation' => ReviewRecommendation::Accept,
        'comments' => 'Excellent',
    ]);

    Review::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer2->id,
        'assignment_id' => $assignment2->id,
        'recommendation' => ReviewRecommendation::MinorRevision,
        'comments' => 'Minor changes needed',
    ]);

    $response = $this->actingAs($chair)
        ->get(route('submissions.show', $submission));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->has('submission.assignments', 2)
        ->has('submission.reviews', 2)
    );
});

test('cannot assign more than the maximum number of reviewers', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();
    $reviewers = collect();
    for ($i = 0; $i < 6; $i++) {
        $reviewers->push(User::factory()->create());
    }

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    foreach ($reviewers as $reviewer) {
        $reviewer->assignRole('committee_member');
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Track with an OPEN review window: PhaseGuards (AD-012) derives legality
    // from the boundary pair, so a trackless submission cannot be staffed or reviewed.
    $track = openReviewTrack($conference);

    $submission = Submission::create([
        'track_id' => $track->id,
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Test Paper',
        'abstract' => 'Test abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($chair)
            ->post(route('submissions.assign-reviewer', $submission), [
                'reviewer_id' => $reviewers[$i]->id,
            ])
            ->assertRedirect();
    }

    expect($submission->fresh()->assignments()->count())->toBe(5);

    $response = $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewers[5]->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['reviewer_id']);
    expect($submission->fresh()->assignments()->count())->toBe(5);
});

test('reassigning replaces a specific assignment even at the max cap', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();
    $reviewers = User::factory()->count(6)->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    foreach ($reviewers as $reviewer) {
        $reviewer->assignRole('committee_member');
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Track with an OPEN review window: PhaseGuards (AD-012) derives legality
    // from the boundary pair, so a trackless submission cannot be staffed or reviewed.
    $track = openReviewTrack($conference);

    $submission = Submission::create([
        'track_id' => $track->id,
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Test Paper',
        'abstract' => 'Test abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($chair)
            ->post(route('submissions.assign-reviewer', $submission), [
                'reviewer_id' => $reviewers[$i]->id,
            ])
            ->assertRedirect();
    }

    $assignmentToReplace = $submission->fresh()->assignments()->first();

    $response = $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewers[5]->id,
            'assignment_id' => $assignmentToReplace->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    expect($submission->fresh()->assignments()->count())->toBe(5);
    expect($submission->fresh()->assignments()->pluck('reviewer_id'))
        ->not->toContain($assignmentToReplace->reviewer_id);
    expect($submission->fresh()->assignments()->pluck('reviewer_id'))
        ->toContain($reviewers[5]->id);
});

test('re-inviting an already-assigned reviewer is allowed at the max cap', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();
    $reviewers = User::factory()->count(5)->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    foreach ($reviewers as $reviewer) {
        $reviewer->assignRole('committee_member');
    }
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Track with an OPEN review window: PhaseGuards (AD-012) derives legality
    // from the boundary pair, so a trackless submission cannot be staffed or reviewed.
    $track = openReviewTrack($conference);

    $submission = Submission::create([
        'track_id' => $track->id,
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Test Paper',
        'abstract' => 'Test abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    for ($i = 0; $i < 5; $i++) {
        $this->actingAs($chair)
            ->post(route('submissions.assign-reviewer', $submission), [
                'reviewer_id' => $reviewers[$i]->id,
            ])
            ->assertRedirect();
    }

    // The first reviewer has declined; re-inviting them must not be blocked
    // by the cap since it flips their existing assignment back to pending.
    $submission->fresh()->assignments()->first()->update([
        'status' => ReviewAssignmentStatus::Declined,
    ]);

    $response = $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewers[0]->id,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasNoErrors();

    expect($submission->fresh()->assignments()->count())->toBe(5);
    expect($submission->fresh()->assignments()->first()->status)
        ->toBe(ReviewAssignmentStatus::Pending);
});

test('reviewer can view submission they are assigned to', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $reviewer->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Track with an OPEN review window: PhaseGuards (AD-012) derives legality
    // from the boundary pair, so a trackless submission cannot be staffed or reviewed.
    $track = openReviewTrack($conference);

    $submission = Submission::create([
        'track_id' => $track->id,
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Test Paper',
        'abstract' => 'Test abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
        'accepted_at' => now(),
    ]);

    $response = $this->actingAs($reviewer)
        ->get(route('submissions.show', $submission));

    $response->assertStatus(200);
});
