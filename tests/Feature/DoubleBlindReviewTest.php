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

function makeSubmission(bool $doubleBlind, User $author): array
{
    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
        'double_blind_review' => $doubleBlind,
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
        'status' => SubmissionStatus::Submitted,
    ]);

    return [$conference, $submission];
}

test('chair can toggle double_blind_review via the conference create form and it persists', function () {
    $chair = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $chair->assignRole('organizer');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $response = $this->actingAs($chair)
        ->post(route('conferences.store'), [
            'title' => 'Blind Conference',
            'description' => 'Test',
            'double_blind_review' => 1,
            'tracks' => [[
                'name' => 'Main Track',
                'submission_open_at' => now()->subDay()->toDateString(),
                'submission_close_at' => now()->addDays(30)->toDateString(),
            ]],
        ]);

    $response->assertRedirect(route('conferences.index'));

    $conference = Conference::where('title', 'Blind Conference')->first();
    expect($conference)->not->toBeNull();
    expect($conference->double_blind_review)->toBeTrue();
});

test('chair can toggle double_blind_review via the conference edit form and it persists', function () {
    $chair = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Blind Conference',
        'description' => 'Test',
        'double_blind_review' => true,
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($chair)
        ->put(route('conferences.update', $conference), [
            'title' => 'Blind Conference',
            'description' => 'Test',
            'double_blind_review' => 0,
            'tracks' => [[
                'name' => 'Main Track',
                'submission_open_at' => now()->subDay()->toDateString(),
                'submission_close_at' => now()->addDays(30)->toDateString(),
            ]],
        ])
        ->assertRedirect(route('conferences.index'));

    expect($conference->fresh()->double_blind_review)->toBeFalse();
});

test('with double-blind enabled, reviewer cannot see author name on the create page', function () {
    Notification::fake();

    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    [$conference, $submission] = makeSubmission(true, $author);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $reviewer->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
        'accepted_at' => now(),
    ]);

    $response = $this->actingAs($reviewer)
        ->get(route('reviews.create').'?assignment_id='.$assignment->id);

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Reviews/Create')
        ->where('double_blind', true)
        ->where('assignment.submission.user', null)
    );
});

test('with double-blind enabled, reviewer cannot see author name after submitting a review', function () {
    Notification::fake();

    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    [$conference, $submission] = makeSubmission(true, $author);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $reviewer->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

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
            'grade' => 5,
        ]);

    $response->assertRedirect();

    $review = Review::where('submission_id', $submission->id)->first();
    $response->assertRedirect(route('reviews.show', $review));

    $show = $this->actingAs($reviewer)->get(route('reviews.show', $review));
    $show->assertInertia(fn (Assert $page) => $page
        ->component('Reviews/Show')
        ->where('double_blind', true)
        ->where('viewer_role', 'reviewer')
        ->missing('review.submission.user')
    );
});

test('with double-blind enabled, author cannot see reviewer name on the review show page', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    [$conference, $submission] = makeSubmission(true, $author);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $reviewer->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

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

    $response = $this->actingAs($author)->get(route('reviews.show', $review));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Reviews/Show')
        ->where('double_blind', true)
        ->where('viewer_role', 'author')
        ->where('review.reviewer', null)
    );
});

test('with double-blind enabled, reviewer and author do not see reviewer names in the submissions show reviews section', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    [$conference, $submission] = makeSubmission(true, $author);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $reviewer->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
        'accepted_at' => now(),
    ]);

    Review::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'assignment_id' => $assignment->id,
        'recommendation' => ReviewRecommendation::Accept,
        'comments' => 'Good',
        'grade' => 5,
    ]);

    // Author view: viewer_role author, double_blind on
    $this->actingAs($author)
        ->get(route('submissions.show', $submission))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Submissions/Show')
            ->where('double_blind_review', true)
            ->where('viewer_role', 'author')
        );

    // Reviewer view: viewer_role reviewer, double_blind on
    $this->actingAs($reviewer)
        ->get(route('submissions.show', $submission))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Submissions/Show')
            ->where('double_blind_review', true)
            ->where('viewer_role', 'reviewer')
        );
});

test('with double-blind disabled, both can see each other names', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();

    [$conference, $submission] = makeSubmission(false, $author);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $reviewer->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
        'accepted_at' => now(),
    ]);

    // Reviewer sees author on create page
    $this->actingAs($reviewer)
        ->get(route('reviews.create').'?assignment_id='.$assignment->id)
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reviews/Create')
            ->where('double_blind', false)
            ->where('assignment.submission.user.id', $author->id)
        );

    $review = Review::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'assignment_id' => $assignment->id,
        'recommendation' => ReviewRecommendation::Accept,
        'comments' => 'Good',
        'grade' => 5,
    ]);

    // Author sees reviewer on show page
    $this->actingAs($author)
        ->get(route('reviews.show', $review))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reviews/Show')
            ->where('double_blind', false)
            ->where('review.reviewer.id', $reviewer->id)
        );
});

test('chair always sees full identity, including when chair is also the reviewer', function () {
    $author = User::factory()->create();
    $chair = User::factory()->create();

    [$conference, $submission] = makeSubmission(true, $author);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    $chair->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $chair->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
        'accepted_at' => now(),
    ]);

    $review = Review::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $chair->id,
        'assignment_id' => $assignment->id,
        'recommendation' => ReviewRecommendation::Accept,
        'comments' => 'Good',
        'grade' => 5,
    ]);

    $response = $this->actingAs($chair)->get(route('reviews.show', $review));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->component('Reviews/Show')
        ->where('double_blind', true)
        ->where('viewer_role', 'chair')
        ->where('review.submission.user.id', $author->id)
        ->where('review.reviewer.id', $chair->id)
    );

    // Chair also sees full names in the submissions show reviews section
    $this->actingAs($chair)
        ->get(route('submissions.show', $submission))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Submissions/Show')
            ->where('double_blind_review', true)
            ->where('viewer_role', 'chair')
        );
});

test('global admin is treated as chair and sees full identity in a double-blind conference', function () {
    $author = User::factory()->create();
    $reviewer = User::factory()->create();
    $admin = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $admin->assignRole('admin');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    [$conference, $submission] = makeSubmission(true, $author);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $reviewer->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

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
        'comments' => 'Good',
        'grade' => 5,
    ]);

    // Review show page: viewer_role chair, both identities visible
    $this->actingAs($admin)
        ->get(route('reviews.show', $review))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Reviews/Show')
            ->where('double_blind', true)
            ->where('viewer_role', 'chair')
            ->where('review.submission.user.id', $author->id)
            ->where('review.reviewer.id', $reviewer->id)
        );

    // Submissions show page: viewer_role chair, reviewers not anonymized
    $this->actingAs($admin)
        ->get(route('submissions.show', $submission))
        ->assertInertia(fn (Assert $page) => $page
            ->component('Submissions/Show')
            ->where('double_blind_review', true)
            ->where('viewer_role', 'chair')
        );
});
