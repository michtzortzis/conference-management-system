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

test('reviewer can create a review with a grade', function () {
    Notification::fake();

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

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
        'accepted_at' => now(),
    ]);

    $response = $this->actingAs($reviewer)
        ->post(route('reviews.store'), [
            'submission_id' => $submission->id,
            'assignment_id' => $assignment->id,
            'recommendation' => 'accept',
            'comments' => 'Excellent paper',
            'grade' => 5,
        ]);

    $response->assertRedirect();
    $response->assertRedirect(route('reviews.show', Review::where('submission_id', $submission->id)->first()));

    $review = Review::where('submission_id', $submission->id)->first();
    expect($review)->not->toBeNull();
    expect($review->grade)->toBe(5);
});

test('grade is required when creating a review', function () {
    Notification::fake();

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

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
        'accepted_at' => now(),
    ]);

    $response = $this->actingAs($reviewer)
        ->post(route('reviews.store'), [
            'submission_id' => $submission->id,
            'assignment_id' => $assignment->id,
            'recommendation' => 'accept',
            'comments' => 'Good paper',
        ]);

    $response->assertSessionHasErrors(['grade']);
    expect(Review::where('submission_id', $submission->id)->count())->toBe(0);
});

test('reviewer can update a review grade', function () {
    Notification::fake();

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

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
        'accepted_at' => now(),
    ]);

    $review = Review::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'assignment_id' => $assignment->id,
        'recommendation' => ReviewRecommendation::Accept,
        'comments' => 'Initial comments',
        'grade' => 2,
    ]);

    $response = $this->actingAs($reviewer)
        ->put(route('reviews.update', $review), [
            'recommendation' => 'accept',
            'comments' => 'Updated comments',
            'grade' => 4,
        ]);

    $response->assertRedirect();

    expect($review->fresh()->grade)->toBe(4);
    expect($review->fresh()->comments)->toBe('Updated comments');
});

test('grade must be between 1 and 5', function () {
    Notification::fake();

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

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
        'accepted_at' => now(),
    ]);

    $response = $this->actingAs($reviewer)
        ->post(route('reviews.store'), [
            'submission_id' => $submission->id,
            'assignment_id' => $assignment->id,
            'recommendation' => 'accept',
            'comments' => 'Too high',
            'grade' => 6,
        ]);

    $response->assertSessionHasErrors(['grade']);
    expect(Review::where('submission_id', $submission->id)->count())->toBe(0);
});

test('grade is displayed on the review show page', function () {
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

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
        'accepted_at' => now(),
    ]);

    $review = Review::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'assignment_id' => $assignment->id,
        'recommendation' => ReviewRecommendation::Accept,
        'comments' => 'Great work',
        'grade' => 5,
    ]);

    $response = $this->actingAs($reviewer)
        ->get(route('reviews.show', $review));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Reviews/Show')
        ->where('review.grade', 5)
    );
});
