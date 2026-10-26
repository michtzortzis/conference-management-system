<?php

use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\ReviewAssignment;
use App\Models\ReviewerConflict;
use App\Models\Submission;
use App\Models\SubmissionBid;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function poolConference(): array
{
    $author = User::factory()->create();
    $chair = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Reviewer Pool Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(30)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $author->assignRole('author');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $track = openReviewTrack($conference);

    $submission = Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => $author->id,
        'title' => 'Pool Paper',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    return compact('conference', 'track', 'submission', 'chair', 'author');
}

function committeeMember(Conference $conference): User
{
    $member = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $member->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $member;
}

/**
 * D-5a.1 — `Submission *-- "0..5" ReviewAssignment` (Fig 3.5a) and the cap node of
 * Fig 3.10. `autoAssignBidders` counted `$assigned`, which starts at zero on every call,
 * so a submission already carrying four accepted reviews could gain five more.
 * The cap is now recounted over ACTIVE rows by `ReviewAssignment::remainingReviewerSlots()`
 * and both assign paths call it.
 */
test('auto-assign respects the cap against assignments that already exist', function () {
    ['conference' => $conference, 'submission' => $submission, 'chair' => $chair] = poolConference();

    // Four seats already taken, plus one declined row that must NOT count as a seat.
    foreach (range(1, 4) as $i) {
        ReviewAssignment::create([
            'submission_id' => $submission->id,
            'reviewer_id' => committeeMember($conference)->id,
            'status' => ReviewAssignmentStatus::Accepted,
            'assigned_at' => now()->subDays(2),
            'accepted_at' => now()->subDays(2),
        ]);
    }
    $declined = committeeMember($conference);
    ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $declined->id,
        'status' => ReviewAssignmentStatus::Declined,
        'assigned_at' => now()->subDays(2),
        'declined_at' => now()->subDays(1),
    ]);

    // Three bidders queue up; only one seat is legally left.
    $bidders = collect(range(1, 3))->map(function () use ($conference, $submission) {
        $bidder = committeeMember($conference);
        SubmissionBid::create(['submission_id' => $submission->id, 'user_id' => $bidder->id]);

        return $bidder;
    });

    $this->actingAs($chair)
        ->post(route('submissions.auto-assign', $submission))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    $fresh = $submission->fresh();

    expect(ReviewAssignment::activeCount($fresh))->toBe(5)
        ->and($fresh->assignments()->count())->toBe(6) // 4 accepted + 1 declined (no seat) + 1 new
        ->and($bidders->filter(fn ($b) => $fresh->assignments()->where('reviewer_id', $b->id)->exists())->count())
        ->toBe(1);
});

/**
 * D-8.1 — Fig 3.8's `Pending --> Accepted ⟨eligible, no declared COI⟩`.
 * A *declared* conflict is authoritative (AD-004): the reviewer stated it themselves, so
 * accepting puts a conflicted review into the record. The policy had no such check.
 */
test('a reviewer with a declared conflict cannot accept an assignment', function () {
    ['conference' => $conference, 'submission' => $submission, 'author' => $author] = poolConference();

    $reviewer = committeeMember($conference);

    ReviewerConflict::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'author_id' => $author->id,
        'level' => 'declared',
        'reason' => 'Collaborated in the last 36 months',
        'overridden' => false,
    ]);

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Pending,
        'assigned_at' => now(),
    ]);

    $this->actingAs($reviewer)
        ->post(route('reviews.accept', $assignment))
        ->assertForbidden();

    expect($assignment->fresh()->status)->toBe(ReviewAssignmentStatus::Pending);
});

test('a reviewer without a declared conflict can still accept', function () {
    ['submission' => $submission] = poolConference();

    $reviewer = committeeMember($submission->conference);

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Pending,
        'assigned_at' => now(),
    ]);

    $this->actingAs($reviewer)
        ->post(route('reviews.accept', $assignment))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($assignment->fresh()->status)->toBe(ReviewAssignmentStatus::Accepted);
});
