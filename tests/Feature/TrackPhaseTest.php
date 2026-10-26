<?php

use App\Enums\DecisionOutcome;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Decision;
use App\Models\Review;
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

function createOrganizer(): User
{
    $user = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $user->assignRole('organizer');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

function validTrack(array $overrides = []): array
{
    return array_merge([
        'name' => 'ML Track',
        'description' => 'Description',
        'submission_open_at' => now()->subDay()->toDateString(),
        'submission_close_at' => now()->addDays(30)->toDateString(),
    ], $overrides);
}

/**
 * Conference and track with the given boundaries, plus the three actors the
 * phase-window tests need: a global author, a chair and a committee member.
 *
 * @param  array<string, mixed>  $boundaries
 * @return array{conference: Conference, track: Track, author: User, chair: User, reviewer: User}
 */
function phaseWindowFixture(array $boundaries): array
{
    $author = User::factory()->create();
    $chair = User::factory()->create();
    $reviewer = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Window Conference',
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $author->assignRole('author');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    $reviewer->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $track = Track::create(array_merge([
        'conference_id' => $conference->id,
        'name' => 'ML Track',
    ], $boundaries));

    return compact('conference', 'track', 'author', 'chair', 'reviewer');
}

describe('Track phases', function () {
    test('conference create requires CFP dates on tracks', function () {
        $organizer = createOrganizer();

        $this->actingAs($organizer)
            ->post(route('conferences.store'), [
                'title' => 'Test Conference',
                'tracks' => [
                    ['name' => 'Track 1'],
                ],
            ])
            ->assertSessionHasErrors(['tracks.0.submission_open_at', 'tracks.0.submission_close_at']);
    });

    test('conference create rejects review_open_at before submission_close_at', function () {
        $organizer = createOrganizer();

        $this->actingAs($organizer)
            ->post(route('conferences.store'), [
                'title' => 'Test Conference',
                'tracks' => [
                    validTrack(['review_open_at' => now()->subDay()->toDateString()]),
                ],
            ])
            ->assertSessionHasErrors('tracks.0.review_open_at');
    });

    test('conference create allows review_open_at equal to submission_close_at', function () {
        $organizer = createOrganizer();

        $this->actingAs($organizer)
            ->post(route('conferences.store'), [
                'title' => 'Test Conference',
                'tracks' => [
                    validTrack([
                        'submission_close_at' => now()->addDays(10)->toDateString(),
                        'review_open_at' => now()->addDays(10)->toDateString(),
                        'review_close_at' => now()->addDays(20)->toDateString(),
                        'decision_open_at' => now()->addDays(20)->toDateString(),
                        'decision_close_at' => now()->addDays(30)->toDateString(),
                    ]),
                ],
            ])
            ->assertSessionHasNoErrors();
    });

    test('conference create persists review and decision windows', function () {
        $organizer = createOrganizer();

        $this->actingAs($organizer)
            ->post(route('conferences.store'), [
                'title' => 'Test Conference',
                'tracks' => [
                    validTrack([
                        'review_open_at' => now()->addDays(30)->toDateString(),
                        'review_close_at' => now()->addDays(40)->toDateString(),
                        'decision_open_at' => now()->addDays(40)->toDateString(),
                        'decision_close_at' => now()->addDays(50)->toDateString(),
                    ]),
                ],
            ]);

        $track = Track::first();
        expect($track)->not->toBeNull();
        expect($track->review_open_at)->not->toBeNull();
        expect($track->review_close_at)->not->toBeNull();
        expect($track->decision_open_at)->not->toBeNull();
        expect($track->decision_close_at)->not->toBeNull();
    });

    test('conference update rejects decision_open_at before review_close_at', function () {
        $user = User::factory()->create();
        $conference = Conference::create([
            'title' => 'Test Conference',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $user->assignRole('chair');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $this->actingAs($user)
            ->put(route('conferences.update', $conference), [
                'title' => 'Updated',
                'tracks' => [
                    validTrack([
                        'review_open_at' => now()->addDays(5)->toDateString(),
                        'review_close_at' => now()->addDays(15)->toDateString(),
                        'decision_open_at' => now()->addDays(10)->toDateString(),
                        'decision_close_at' => now()->addDays(25)->toDateString(),
                    ]),
                ],
            ])
            ->assertSessionHasErrors('tracks.0.decision_open_at');
    });
});

describe('Review window enforcement', function () {
    test('assigning a reviewer is blocked after review_close_at', function () {
        Notification::fake();

        $author = User::factory()->create();
        $chair = User::factory()->create();
        $reviewer = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $chair->assignRole('chair');
        $reviewer->assignRole('committee_member');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $track = Track::create([
            'conference_id' => $conference->id,
            'name' => 'ML Track',
            'submission_open_at' => now()->subDay(),
            'submission_close_at' => now()->addDays(30),
            'review_open_at' => now()->subDays(2),
            // A close reads as the end of its day, so "closed" needs a day
            // that has passed, not a few hours.
            'review_close_at' => now()->subDay(),
        ]);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'track_id' => $track->id,
            'user_id' => $author->id,
            'title' => 'Paper',
            'abstract' => 'Abstract',
            'status' => SubmissionStatus::Submitted,
        ]);

        $response = $this->actingAs($chair)
            ->post(route('submissions.assign-reviewer', $submission), [
                'reviewer_id' => $reviewer->id,
            ]);

        $response->assertSessionHasErrors('reviewer_id');
        expect($submission->fresh()->assignments()->count())->toBe(0);
    });

    test('assigning a reviewer is allowed when review window is open', function () {
        Notification::fake();

        $author = User::factory()->create();
        $chair = User::factory()->create();
        $reviewer = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $chair->assignRole('chair');
        $reviewer->assignRole('committee_member');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $track = Track::create([
            'conference_id' => $conference->id,
            'name' => 'ML Track',
            'submission_open_at' => now()->subDay(),
            'submission_close_at' => now()->addDays(30),
            'review_open_at' => now()->subDay(),
            'review_close_at' => now()->addDays(10),
        ]);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'track_id' => $track->id,
            'user_id' => $author->id,
            'title' => 'Paper',
            'abstract' => 'Abstract',
            'status' => SubmissionStatus::Submitted,
        ]);

        $response = $this->actingAs($chair)
            ->post(route('submissions.assign-reviewer', $submission), [
                'reviewer_id' => $reviewer->id,
            ]);

        $response->assertSessionHasNoErrors();
        expect($submission->fresh()->assignments()->count())->toBe(1);
    });
});

describe('Decision window enforcement', function () {
    test('decision is blocked outside the decision window', function () {
        Notification::fake();

        $author = User::factory()->create();
        $chair = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $chair->assignRole('chair');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $track = Track::create([
            'conference_id' => $conference->id,
            'name' => 'ML Track',
            'submission_open_at' => now()->subDay(),
            'submission_close_at' => now()->addDays(30),
            'decision_open_at' => now()->addDays(10),
            'decision_close_at' => now()->addDays(20),
        ]);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'track_id' => $track->id,
            'user_id' => $author->id,
            'title' => 'Paper',
            'abstract' => 'Abstract',
            'status' => SubmissionStatus::UnderReview,
        ]);

        $this->actingAs($chair)
            ->post(route('submissions.decision.store', $submission), [
                'decision' => 'accepted',
            ])
            ->assertSessionHasErrors('decision');

        expect($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);
    });

    test('decision is allowed inside the decision window', function () {
        Notification::fake();

        $author = User::factory()->create();
        $chair = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $chair->assignRole('chair');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $track = Track::create([
            'conference_id' => $conference->id,
            'name' => 'ML Track',
            'submission_open_at' => now()->subDay(),
            'submission_close_at' => now()->addDays(30),
            'decision_open_at' => now()->subDay(),
            'decision_close_at' => now()->addDays(10),
        ]);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'track_id' => $track->id,
            'user_id' => $author->id,
            'title' => 'Paper',
            'abstract' => 'Abstract',
            'status' => SubmissionStatus::UnderReview,
        ]);

        $this->actingAs($chair)
            ->post(route('submissions.decision.store', $submission), [
                'decision' => 'accepted',
            ])
            ->assertSessionHasNoErrors();

        expect($submission->fresh()->status)->toBe(SubmissionStatus::Accepted);
    });
});

describe('Review window hard gate (AD-012 / SD-05)', function () {
    // INVERTED with D-8.3 (2026-09-21). This block used to be named `Review window
    // soft-gate` and proved the opposite: that a review was still written after the window
    // closed with nothing but a UI warning flag. That behaviour WAS the finding
    // (IN-15 / D12, Fig 3.3): `create()` computed `review_window_open` as a render prop and
    // no write path consulted it. Legality now comes from `PhaseGuards::reviewable()`.

    test('a review is refused after the review window has closed', function () {
        ['conference' => $conference, 'track' => $track, 'author' => $author, 'reviewer' => $reviewer] = phaseWindowFixture([
            'submission_open_at' => now()->subDays(20),
            'submission_close_at' => now()->subDays(10),
            'review_open_at' => now()->subDays(5),
            // End-of-day close semantics: yesterday is closed, today would not be.
            'review_close_at' => now()->subDay(),
        ]);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'track_id' => $track->id,
            'user_id' => $author->id,
            'title' => 'Late Review Paper',
            'abstract' => 'Abstract',
            'status' => SubmissionStatus::Submitted,
        ]);

        $assignment = ReviewAssignment::create([
            'submission_id' => $submission->id,
            'reviewer_id' => $reviewer->id,
            'status' => ReviewAssignmentStatus::Accepted,
            'assigned_at' => now()->subDays(4),
            'accepted_at' => now()->subDays(4),
        ]);

        $this->actingAs($reviewer)
            ->post(route('reviews.store'), [
                'submission_id' => $submission->id,
                'assignment_id' => $assignment->id,
                'recommendation' => 'accept',
                'grade' => 5,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('submission');

        expect($submission->fresh()->reviews()->count())->toBe(0);
    });

    test('a review is refused before the review window has opened', function () {
        ['conference' => $conference, 'track' => $track, 'author' => $author, 'reviewer' => $reviewer] = phaseWindowFixture([
            'submission_open_at' => now()->subDays(10),
            'submission_close_at' => now()->subDay(),
            'review_open_at' => now()->addDays(3),
            'review_close_at' => now()->addDays(10),
        ]);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'track_id' => $track->id,
            'user_id' => $author->id,
            'title' => 'Early Review Paper',
            'abstract' => 'Abstract',
            'status' => SubmissionStatus::Submitted,
        ]);

        $assignment = ReviewAssignment::create([
            'submission_id' => $submission->id,
            'reviewer_id' => $reviewer->id,
            'status' => ReviewAssignmentStatus::Accepted,
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);

        // Round 1 refuses before the window opens in EVERY mode — the revision-round
        // branch of AD-012 cannot rescue it, because no decision exists yet.
        $this->actingAs($reviewer)
            ->post(route('reviews.store'), [
                'submission_id' => $submission->id,
                'assignment_id' => $assignment->id,
                'recommendation' => 'minor_revision',
                'grade' => 3,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('submission');

        expect($submission->fresh()->reviews()->count())->toBe(0);
    });

    test('editing a review is refused once the window has closed', function () {
        ['conference' => $conference, 'track' => $track, 'author' => $author, 'reviewer' => $reviewer] = phaseWindowFixture([
            'submission_open_at' => now()->subDays(20),
            'submission_close_at' => now()->subDays(10),
            'review_open_at' => now()->subDays(5),
            'review_close_at' => now()->addDays(2),
        ]);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'track_id' => $track->id,
            'user_id' => $author->id,
            'title' => 'Edited Review Paper',
            'abstract' => 'Abstract',
            'status' => SubmissionStatus::Submitted,
        ]);

        $assignment = ReviewAssignment::create([
            'submission_id' => $submission->id,
            'reviewer_id' => $reviewer->id,
            'status' => ReviewAssignmentStatus::Accepted,
            'assigned_at' => now()->subDays(4),
            'accepted_at' => now()->subDays(4),
        ]);

        $review = Review::create([
            'submission_id' => $submission->id,
            'reviewer_id' => $reviewer->id,
            'assignment_id' => $assignment->id,
            'recommendation' => 'accept',
            'grade' => 5,
            'comments' => 'written while open',
        ]);

        // Past the close boundary: update() is a review write, so it carries the same
        // gate as store(). Leaving it open would make "edit" the hole in the window.
        $this->travelTo(now()->addDays(4));

        $this->actingAs($reviewer)
            ->put(route('reviews.update', $review), [
                'recommendation' => 'reject',
                'grade' => 1,
                'comments' => 'rewritten after close',
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('submission');

        $this->travelBack();

        expect($review->fresh()->recommendation->value)->toBe('accept');
        expect($review->fresh()->comments)->toBe('written while open');
    });

    test('a round-2 review is accepted while the decision window is open', function () {
        // The case a bare `phaseClosesAt(Phase::Review)` check could never serve:
        // review closed, decision window open, newest decision asks for revisions.
        ['conference' => $conference, 'track' => $track, 'author' => $author, 'chair' => $chair, 'reviewer' => $reviewer] = phaseWindowFixture([
            'submission_open_at' => now()->subDays(30),
            'submission_close_at' => now()->subDays(20),
            'review_open_at' => now()->subDays(15),
            'review_close_at' => now()->subDays(2),
            'decision_open_at' => now()->subDay(),
            'decision_close_at' => now()->addDays(5),
        ]);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'track_id' => $track->id,
            'user_id' => $author->id,
            'title' => 'Revision Round Paper',
            'abstract' => 'Abstract',
            'status' => SubmissionStatus::MinorRevision,
        ]);

        Decision::create([
            'submission_id' => $submission->id,
            'decided_by_id' => $chair->id,
            'decision' => DecisionOutcome::MinorRevision,
            'rationale' => 'Add the round-2 evidence',
            'revision_round' => 1,
        ]);

        $assignment = ReviewAssignment::create([
            'submission_id' => $submission->id,
            'reviewer_id' => $reviewer->id,
            'status' => ReviewAssignmentStatus::Accepted,
            'assigned_at' => now()->subDays(14),
            'accepted_at' => now()->subDays(14),
        ]);

        $this->actingAs($reviewer)
            ->post(route('reviews.store'), [
                'submission_id' => $submission->id,
                'assignment_id' => $assignment->id,
                'recommendation' => 'accept',
                'grade' => 4,
                'comments' => 'round 2',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        expect($submission->fresh()->reviews()->count())->toBe(1);
    });

    test('review page flags window open when review window is active', function () {
        $author = User::factory()->create();
        $reviewer = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $reviewer->assignRole('committee_member');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $track = Track::create([
            'conference_id' => $conference->id,
            'name' => 'ML Track',
            'submission_open_at' => now()->subDay(),
            'submission_close_at' => now()->addDays(30),
            'review_open_at' => now()->subDay(),
            'review_close_at' => now()->addDays(10),
        ]);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'track_id' => $track->id,
            'user_id' => $author->id,
            'title' => 'Paper',
            'abstract' => 'Abstract',
            'status' => SubmissionStatus::Submitted,
        ]);

        $assignment = ReviewAssignment::create([
            'submission_id' => $submission->id,
            'reviewer_id' => $reviewer->id,
            'status' => ReviewAssignmentStatus::Accepted,
            'assigned_at' => now(),
            'accepted_at' => now(),
        ]);

        $this->actingAs($reviewer)
            ->get(route('reviews.create', ['assignment_id' => $assignment->id]))
            ->assertInertia(fn (Assert $page) => $page
                ->where('review_window_open', true)
            );
    });
});

describe('Boundary convention', function () {
    // A boundary is a day, not an instant: an `*_open_at` reads as the start of
    // its day and an `*_close_at` as the end of it. These tests pin the last
    // hour of a closing day, which is the hour the old 00:00 reading threw away.
    afterEach(function () {
        $this->travelBack();
    });

    test('a track closing today still accepts a submission at 23:00', function () {
        ['conference' => $conference, 'track' => $track, 'author' => $author] = phaseWindowFixture([
            'submission_open_at' => '2026-09-01',
            'submission_close_at' => '2026-09-10',
        ]);

        $this->travelTo('2026-09-10 23:00:00');

        $this->actingAs($author)
            ->post(route('submissions.store'), [
                'conference_id' => $conference->id,
                'track_id' => $track->id,
                'title' => 'Late Entry',
                'abstract' => 'Filed in the last hour of the CFP.',
            ])
            ->assertSessionHasNoErrors();

        expect($conference->submissions()->count())->toBe(1);
    });

    test('a draft can still be submitted at 23:00 on the day the CFP closes', function () {
        Notification::fake();

        ['track' => $track, 'author' => $author] = phaseWindowFixture([
            'submission_open_at' => '2026-09-01',
            'submission_close_at' => '2026-09-10',
        ]);

        $submission = Submission::create([
            'conference_id' => $track->conference_id,
            'track_id' => $track->id,
            'user_id' => $author->id,
            'title' => 'Paper',
            'abstract' => 'Abstract',
            'status' => SubmissionStatus::Draft,
        ]);

        $this->travelTo('2026-09-10 23:00:00');

        // A file is required to leave Draft (D-7.1, the `>=1 file` guard of Fig 3.7);
        // the boundary being probed here is the clock, not the upload.
        attachFileToSubmission($submission);

        $this->actingAs($author)
            ->post(route('submissions.submit', $submission))
            ->assertSessionHasNoErrors();

        expect($submission->fresh()->status)->toBe(SubmissionStatus::Submitted);
    });

    test('a submission is refused the day after the CFP closed, even while another track is open', function () {
        ['conference' => $conference, 'track' => $track, 'author' => $author] = phaseWindowFixture([
            'submission_open_at' => '2026-09-01',
            'submission_close_at' => '2026-09-10',
        ]);

        // A second open track keeps the policy satisfied, so this exercises the
        // per-track window check in the controller rather than a blanket 403.
        $conference->tracks()->create([
            'name' => 'Still Open Track',
            'submission_open_at' => '2026-09-01',
            'submission_close_at' => '2026-09-30',
        ]);

        $this->travelTo('2026-09-11 09:00:00');

        $this->actingAs($author)
            ->post(route('submissions.store'), [
                'conference_id' => $conference->id,
                'track_id' => $track->id,
                'title' => 'Too Late',
                'abstract' => 'The CFP closed yesterday.',
            ])
            ->assertSessionHasErrors('track_id');

        expect(Submission::count())->toBe(0);
    });

    test('the CFP and review phases overlap when the CFP closes the day review opens', function () {
        Notification::fake();

        ['conference' => $conference, 'track' => $track, 'author' => $author, 'chair' => $chair, 'reviewer' => $reviewer] = phaseWindowFixture([
            'submission_open_at' => '2026-09-01',
            'submission_close_at' => '2026-09-10',
            'review_open_at' => '2026-09-10',
            'review_close_at' => '2026-09-20',
        ]);

        $this->travelTo('2026-09-10 12:00:00');

        // Closes at end of day, opens at the start of it, so on the shared day
        // both phases are open at once. That overlap is the convention, not a
        // bug to "fix" by making the close exclusive.
        $this->actingAs($author)
            ->post(route('submissions.store'), [
                'conference_id' => $conference->id,
                'track_id' => $track->id,
                'title' => 'Overlapping Entry',
                'abstract' => 'Filed while review is already open.',
            ])
            ->assertSessionHasNoErrors();

        $submission = Submission::where('title', 'Overlapping Entry')->firstOrFail();

        // Fig 3.7's `>=1 file` guard now runs in SubmissionPolicy::submit (D-7.1), so the
        // draft needs a file before it may leave Draft. The boundary under test is the
        // clock, not the file rule.
        attachFileToSubmission($submission);

        $this->actingAs($author)
            ->post(route('submissions.submit', $submission))
            ->assertSessionHasNoErrors();

        $this->actingAs($chair)
            ->post(route('submissions.assign-reviewer', $submission), [
                'reviewer_id' => $reviewer->id,
            ])
            ->assertSessionHasNoErrors();

        expect($submission->assignments()->count())->toBe(1);
    });

    test('assigning a reviewer is allowed until the end of the review closing day', function () {
        Notification::fake();

        ['track' => $track, 'author' => $author, 'chair' => $chair, 'reviewer' => $reviewer] = phaseWindowFixture([
            'submission_open_at' => '2026-09-01',
            'submission_close_at' => '2026-09-05',
            'review_open_at' => '2026-09-06',
            'review_close_at' => '2026-09-15',
        ]);

        $submission = Submission::create([
            'conference_id' => $track->conference_id,
            'track_id' => $track->id,
            'user_id' => $author->id,
            'title' => 'Paper',
            'abstract' => 'Abstract',
            'status' => SubmissionStatus::Submitted,
        ]);

        $this->travelTo('2026-09-15 23:30:00');

        $this->actingAs($chair)
            ->post(route('submissions.assign-reviewer', $submission), [
                'reviewer_id' => $reviewer->id,
            ])
            ->assertSessionHasNoErrors();

        expect($submission->fresh()->assignments()->count())->toBe(1);
    });

    test('a decision is allowed until the end of the decision closing day', function () {
        Notification::fake();

        ['track' => $track, 'author' => $author, 'chair' => $chair] = phaseWindowFixture([
            'submission_open_at' => '2026-09-01',
            'submission_close_at' => '2026-09-05',
            'review_open_at' => '2026-09-06',
            'review_close_at' => '2026-09-19',
            'decision_open_at' => '2026-09-20',
            'decision_close_at' => '2026-09-25',
        ]);

        $submission = Submission::create([
            'conference_id' => $track->conference_id,
            'track_id' => $track->id,
            'user_id' => $author->id,
            'title' => 'Paper',
            'abstract' => 'Abstract',
            'status' => SubmissionStatus::UnderReview,
        ]);

        $this->travelTo('2026-09-25 23:30:00');

        $this->actingAs($chair)
            ->post(route('submissions.decision.store', $submission), [
                'decision' => 'accepted',
            ])
            ->assertSessionHasNoErrors();

        expect($submission->fresh()->status)->toBe(SubmissionStatus::Accepted);
    });
});
