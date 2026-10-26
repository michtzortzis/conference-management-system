<?php

use App\Enums\ReviewRecommendation;
use App\Models\Conference;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});
describe('ReviewPolicy', function () {
    test('assigned reviewer can update review', function () {
        $reviewer = User::factory()->create();
        $author = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        // Track with an OPEN review window: PhaseGuards (AD-012) derives legality
        // from the boundary pair, so a trackless submission cannot be staffed or reviewed.
        $track = openReviewTrack($conference);

        $submission = Submission::create([
            'track_id' => $track->id,
            'conference_id' => $conference->id,
            'user_id' => $author->id,
            'title' => 'Test Paper',
            'abstract' => 'Test abstract',
            'status' => 'submitted',
        ]);

        $assignment = ReviewAssignment::create([
            'submission_id' => $submission->id,
            'reviewer_id' => $reviewer->id,
            'status' => 'accepted',
            'assigned_at' => now(),
        ]);

        $review = Review::create([
            'submission_id' => $submission->id,
            'reviewer_id' => $reviewer->id,
            'assignment_id' => $assignment->id,
            'comments' => 'Test comments',
            'recommendation' => ReviewRecommendation::Accept,
        ]);

        $response = $this->actingAs($reviewer)
            ->from(route('reviews.show', $review))
            ->put(route('reviews.update', $review), [
                'comments' => 'Updated comments',
                'recommendation' => 'accept',
            ]);

        $response->assertRedirect();
        expect($review->fresh()->recommendation)->toBe(ReviewRecommendation::Accept);
    });

    test('non-assigned user cannot update review', function () {
        $reviewer = User::factory()->create();
        $otherUser = User::factory()->create();
        $author = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        // Track with an OPEN review window: PhaseGuards (AD-012) derives legality
        // from the boundary pair, so a trackless submission cannot be staffed or reviewed.
        $track = openReviewTrack($conference);

        $submission = Submission::create([
            'track_id' => $track->id,
            'conference_id' => $conference->id,
            'user_id' => $author->id,
            'title' => 'Test Paper',
            'abstract' => 'Test abstract',
            'status' => 'submitted',
        ]);

        $review = Review::create([
            'submission_id' => $submission->id,
            'reviewer_id' => $reviewer->id,
            'comments' => 'Test comments',
            'recommendation' => ReviewRecommendation::Accept,
        ]);

        $response = $this->actingAs($otherUser)
            ->from(route('reviews.show', $review))
            ->put(route('reviews.update', $review), [
                'comments' => 'Updated comments',
                'recommendation' => 'accept',
            ]);

        $response->assertStatus(403);
    });

    test('committee member can create review for submission in their conference', function () {
        $reviewer = User::factory()->create();
        $author = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
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
            'status' => 'submitted',
        ]);

        $assignment = ReviewAssignment::create([
            'submission_id' => $submission->id,
            'reviewer_id' => $reviewer->id,
            'status' => 'accepted',
            'assigned_at' => now(),
        ]);

        $response = $this->actingAs($reviewer)
            ->from(route('reviews.create'))
            ->post(route('reviews.store'), [
                'submission_id' => $submission->id,
                'assignment_id' => $assignment->id,
                'comments' => 'Review comments',
                'recommendation' => 'accept',
                'grade' => 5,
            ]);

        $response->assertRedirect();
        expect(Review::where('submission_id', $submission->id)->count())->toBe(1);
    });

    test('chair can view all reviews in their conference', function () {
        $chair = User::factory()->create();
        $author = User::factory()->create();
        $reviewer = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $chair->assignRole('chair');
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
            'status' => 'submitted',
        ]);

        $review = Review::create([
            'submission_id' => $submission->id,
            'reviewer_id' => $reviewer->id,
            'comments' => 'Review comments',
            'recommendation' => ReviewRecommendation::Accept,
        ]);

        $response = $this->actingAs($chair)
            ->get(route('reviews.show', $review));

        $response->assertStatus(200);
    });
});
