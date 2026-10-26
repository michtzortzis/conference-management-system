<?php

use App\Enums\DecisionOutcome;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\ReviewRecommendation;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Decision;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use App\Notifications\DecisionMade;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('full end-to-end flow: submit, assign, review, decide', function () {
    Notification::fake();

    $author = User::factory()->create();
    $reviewer1 = User::factory()->create();
    $reviewer2 = User::factory()->create();
    $chair = User::factory()->create();

    $conference = Conference::create([
        'title' => 'AI Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $chair->assignRole('chair');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    $reviewer1->assignRole('committee_member');
    $reviewer2->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $track = Track::create([
        'conference_id' => $conference->id,
        'name' => 'ML Track',
        'submission_open_at' => now()->subDay()->toDateString(),
        'submission_close_at' => now()->addDays(30)->toDateString(),
    ]);

    // Step 1: Create and submit
    $submission = Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => $author->id,
        'title' => 'Deep Learning Paper',
        'abstract' => 'Novel approach',
        'status' => SubmissionStatus::Draft,
    ]);

    expect($submission->status)->toBe(SubmissionStatus::Draft);

    // Step 1a: The author submits the draft from the UI route. The submit policy (D-7.1)
    // enforces Fig 3.7's `>=1 file` guard, so the draft needs a file to leave Draft.
    attachFileToSubmission($submission);
    test()->actingAs($author)
        ->post(route('submissions.submit', $submission))
        ->assertRedirect();

    $submission->refresh();

    expect($submission->status)->toBe(SubmissionStatus::Submitted);

    // Step 1b: Chair moves the submission into review from the UI route (SD-01)
    test()->actingAs($chair)
        ->post(route('submissions.start-review', $submission))
        ->assertRedirect();

    $submission->refresh();

    expect($submission->status)->toBe(SubmissionStatus::UnderReview);

    // Step 2: Chair assigns two reviewers
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

    expect($assignment1->status)->toBe(ReviewAssignmentStatus::Pending);
    expect($assignment2->status)->toBe(ReviewAssignmentStatus::Pending);

    // Step 3: Reviewers accept their assignments
    $assignment1->update([
        'status' => ReviewAssignmentStatus::Accepted,
        'accepted_at' => now(),
    ]);

    $assignment2->update([
        'status' => ReviewAssignmentStatus::Accepted,
        'accepted_at' => now(),
    ]);

    expect($assignment1->fresh()->status)->toBe(ReviewAssignmentStatus::Accepted);
    expect($assignment2->fresh()->status)->toBe(ReviewAssignmentStatus::Accepted);

    // Step 4: Reviewer 1 writes review
    $review1 = Review::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer1->id,
        'assignment_id' => $assignment1->id,
        'recommendation' => ReviewRecommendation::Accept,
        'comments' => 'Excellent paper',
    ]);

    expect($review1->recommendation)->toBe(ReviewRecommendation::Accept);

    // Step 4b: Reviewer 2 writes review
    $review2 = Review::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer2->id,
        'assignment_id' => $assignment2->id,
        'recommendation' => ReviewRecommendation::MinorRevision,
        'comments' => 'Good but needs minor changes',
    ]);

    expect($review2->recommendation)->toBe(ReviewRecommendation::MinorRevision);

    // Step 4c: Verify both reviews exist on the submission
    expect($submission->assignments()->count())->toBe(2);
    expect($submission->reviews()->count())->toBe(2);

    // Step 5: Chair makes decision (accepted)
    $response = $this->actingAs($chair)
        ->from(route('submissions.show', $submission))
        ->post(route('submissions.decision.store', $submission), [
            'decision' => 'accepted',
            'rationale' => 'Approved by committee',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');

    // Step 6: Verify status transitions
    expect($submission->fresh()->status)->toBe(SubmissionStatus::Accepted);

    // Step 7: Verify decision record
    $decision = Decision::where('submission_id', $submission->id)->first();
    expect($decision)->not->toBeNull();
    expect($decision->decision)->toBe(DecisionOutcome::Accepted);
    expect($decision->decided_by_id)->toBe($chair->id);
    expect($decision->rationale)->toBe('Approved by committee');
    expect($decision->revision_round)->toBe(1);

    // Step 8: Verify notifications were queued
    Notification::assertSentTo(
        $author,
        DecisionMade::class,
    );
});

test('full e2e: revision cycle then accept', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();

    $conference = Conference::create([
        'title' => 'AI Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Review already closed, decision window open: exactly the state a revision cycle
    // happens in, and the state AD-012's second branch is about (round-2 legality comes
    // from the open revision round during the decision window, not from review_close_at).
    $track = openReviewTrack($conference, 'Main Track', [
        'review_close_at' => now()->subDay(),
        'decision_open_at' => now()->subDay(),
        'decision_close_at' => now()->addDays(5),
    ]);

    $submission = Submission::create([
        'track_id' => $track->id,
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Paper needing revisions',
        'abstract' => 'Good but needs work',
        'status' => 'under_review',
    ]);

    // First decision: minor_revision
    $this->actingAs($chair)
        ->from(route('submissions.show', $submission))
        ->post(route('submissions.decision.store', $submission), [
            'decision' => 'minor_revision',
            'rationale' => 'Minor changes needed',
        ]);

    expect($submission->fresh()->status)->toBe(SubmissionStatus::MinorRevision);

    $firstDecision = Decision::where('submission_id', $submission->id)->first();
    expect($firstDecision->revision_round)->toBe(1);

    // Second decision: accept (only accept/reject allowed during revision)
    $this->actingAs($chair)
        ->from(route('submissions.show', $submission))
        ->post(route('submissions.decision.store', $submission), [
            'decision' => 'accepted',
            'rationale' => 'Revisions satisfactory',
        ]);

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Accepted);

    $decisions = Decision::where('submission_id', $submission->id)->orderBy('id')->get();
    expect($decisions)->toHaveCount(2);
    expect($decisions->last()->revision_round)->toBe(2);
    expect($decisions->last()->decision)->toBe(DecisionOutcome::Accepted);
});
