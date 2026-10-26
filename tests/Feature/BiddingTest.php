<?php

use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\SubmissionBid;
use App\Models\SubmissionCoAuthor;
use App\Models\Track;
use App\Models\User;
use App\Notifications\BidReceived;
use App\Notifications\ReviewerAssigned;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

afterEach(function () {
    $this->travelBack();
});

function makeBiddableSubmission(User $author, array $trackDates = []): Submission
{
    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    $track = Track::create([
        'conference_id' => $conference->id,
        'name' => 'Main Track',
        'submission_open_at' => $trackDates['submission_open_at'] ?? now()->subDays(10),
        'submission_close_at' => $trackDates['submission_close_at'] ?? now()->subDays(5),
        'review_open_at' => $trackDates['review_open_at'] ?? now()->addDays(2),
        'review_close_at' => $trackDates['review_close_at'] ?? now()->addDays(10),
    ]);

    return Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => $author->id,
        'title' => 'Test Paper',
        'abstract' => 'Test abstract',
        'status' => SubmissionStatus::Submitted,
    ]);
}

function assignCommitteeRole(User $user, Conference $conference): void
{
    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $user->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);
}

test('committee member can bid after CFP close within the derived window', function () {
    Notification::fake();

    $author = User::factory()->create();
    $committee = User::factory()->create();
    $submission = makeBiddableSubmission($author);
    assignCommitteeRole($committee, $submission->conference);

    $this->actingAs($committee)
        ->post(route('submissions.bid', $submission))
        ->assertRedirect();

    expect(SubmissionBid::where('submission_id', $submission->id)
        ->where('user_id', $committee->id)->exists())->toBeTrue();

    Notification::assertSentTo($committee, BidReceived::class);
});

test('bidding page lists only biddable submissions with bid state', function () {
    $author = User::factory()->create();
    $committee = User::factory()->create();
    $submission = makeBiddableSubmission($author);
    assignCommitteeRole($committee, $submission->conference);

    SubmissionBid::create([
        'submission_id' => $submission->id,
        'user_id' => $committee->id,
    ]);

    $response = $this->actingAs($committee)->get(route('bidding.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->has('submissions', 1)
        ->where('bid_submission_ids.0', $submission->id)
    );
});

test('non-committee member cannot bid', function () {
    $author = User::factory()->create();
    $other = User::factory()->create();
    $submission = makeBiddableSubmission($author);

    $this->actingAs($other)
        ->post(route('submissions.bid', $submission))
        ->assertRedirect()
        ->assertSessionHasErrors(['submission']);

    expect(SubmissionBid::count())->toBe(0);
});

test('draft submission is not biddable', function () {
    $author = User::factory()->create();
    $committee = User::factory()->create();
    $submission = makeBiddableSubmission($author);
    assignCommitteeRole($committee, $submission->conference);
    $submission->update(['status' => SubmissionStatus::Draft]);

    $this->actingAs($committee)
        ->post(route('submissions.bid', $submission))
        ->assertRedirect()
        ->assertSessionHasErrors(['submission']);

    expect(SubmissionBid::count())->toBe(0);
});

test('cannot bid when already assigned to review the submission', function () {
    $author = User::factory()->create();
    $committee = User::factory()->create();
    $submission = makeBiddableSubmission($author);
    assignCommitteeRole($committee, $submission->conference);

    ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $committee->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
    ]);

    $this->actingAs($committee)
        ->post(route('submissions.bid', $submission))
        ->assertRedirect()
        ->assertSessionHasErrors(['submission']);

    expect(SubmissionBid::count())->toBe(0);
});

test('cannot bid on own submission', function () {
    $author = User::factory()->create();
    $submission = makeBiddableSubmission($author);
    assignCommitteeRole($author, $submission->conference);

    $this->actingAs($author)
        ->post(route('submissions.bid', $submission))
        ->assertRedirect()
        ->assertSessionHasErrors(['submission']);

    expect(SubmissionBid::count())->toBe(0);
});

test('cannot bid on a submission where they are a registered co-author', function () {
    $submitter = User::factory()->create();
    $coAuthor = User::factory()->create();
    $submission = makeBiddableSubmission($submitter);
    assignCommitteeRole($coAuthor, $submission->conference);

    SubmissionCoAuthor::create([
        'submission_id' => $submission->id,
        'user_id' => $coAuthor->id,
        'first_name' => $coAuthor->first_name,
        'last_name' => $coAuthor->last_name,
        'email' => $coAuthor->email,
    ]);

    $this->actingAs($coAuthor)
        ->post(route('submissions.bid', $submission))
        ->assertRedirect()
        ->assertSessionHasErrors(['submission']);

    expect(SubmissionBid::count())->toBe(0);
});

test('multi-bid is capped at max_reviewers', function () {
    $author = User::factory()->create();
    $committee = User::factory()->create();
    $conference = Conference::create([
        'title' => 'Test Conference',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);
    assignCommitteeRole($committee, $conference);

    $maxReviewers = (int) config('conference.max_reviewers', 5);

    // Seed max bids directly to reach the cap.
    for ($i = 0; $i < $maxReviewers; $i++) {
        $track = Track::create([
            'conference_id' => $conference->id,
            'name' => "Track {$i}",
            'submission_open_at' => now()->subDays(10),
            'submission_close_at' => now()->subDays(5),
            'review_open_at' => now()->addDays(2),
            'review_close_at' => now()->addDays(10),
        ]);
        $s = Submission::create([
            'conference_id' => $conference->id,
            'track_id' => $track->id,
            'user_id' => $author->id,
            'title' => "Paper {$i}",
            'abstract' => 'Test',
            'status' => SubmissionStatus::Submitted,
        ]);
        SubmissionBid::create(['submission_id' => $s->id, 'user_id' => $committee->id]);
    }

    $overTrack = Track::create([
        'conference_id' => $conference->id,
        'name' => 'Over Track',
        'submission_open_at' => now()->subDays(10),
        'submission_close_at' => now()->subDays(5),
        'review_open_at' => now()->addDays(2),
        'review_close_at' => now()->addDays(10),
    ]);
    $over = Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $overTrack->id,
        'user_id' => $author->id,
        'title' => 'Over Paper',
        'abstract' => 'Test',
        'status' => SubmissionStatus::Submitted,
    ]);

    $this->actingAs($committee)
        ->post(route('submissions.bid', $over))
        ->assertRedirect()
        ->assertSessionHasErrors(['submission']);

    expect(SubmissionBid::where('user_id', $committee->id)->count())
        ->toBe($maxReviewers);
});

test('unbid withdraws a bid', function () {
    $author = User::factory()->create();
    $committee = User::factory()->create();
    $submission = makeBiddableSubmission($author);
    assignCommitteeRole($committee, $submission->conference);

    SubmissionBid::create([
        'submission_id' => $submission->id,
        'user_id' => $committee->id,
    ]);

    $this->actingAs($committee)
        ->delete(route('submissions.unbid', $submission))
        ->assertRedirect();

    expect(SubmissionBid::where('submission_id', $submission->id)->count())
        ->toBe(0);
});

test('auto-assign creates assignments for bidders up to cap and notifies', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();
    $bidder1 = User::factory()->create();
    $bidder2 = User::factory()->create();
    $bidder3 = User::factory()->create();

    // Review must already be open to staff reviewers (AD-012 / SD-05): bids are collected
    // between CFP close and review open, staffing happens after it. The pre-SD-05 auto-assign
    // path refused only after review_close_at, so this fixture never opened the window.
    $submission = makeBiddableSubmission($author, ['review_open_at' => now()->subDay()]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($submission->conference_id);
    $chair->assignRole('chair');
    $bidder1->assignRole('committee_member');
    $bidder2->assignRole('committee_member');
    $bidder3->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    SubmissionBid::create(['submission_id' => $submission->id, 'user_id' => $bidder1->id]);
    SubmissionBid::create(['submission_id' => $submission->id, 'user_id' => $bidder2->id]);
    SubmissionBid::create(['submission_id' => $submission->id, 'user_id' => $bidder3->id]);

    $this->actingAs($chair)
        ->post(route('submissions.auto-assign', $submission))
        ->assertRedirect();

    $assignments = $submission->fresh()->assignments()->get();
    expect($assignments->count())->toBe(3);
    expect($assignments->pluck('reviewer_id'))
        ->toContain($bidder1->id, $bidder2->id, $bidder3->id);

    Notification::assertSentTo($bidder1, ReviewerAssigned::class);
    Notification::assertSentTo($bidder2, ReviewerAssigned::class);
    Notification::assertSentTo($bidder3, ReviewerAssigned::class);

    // Bids are withdrawn after assignment.
    expect(SubmissionBid::where('submission_id', $submission->id)->count())
        ->toBe(0);
});

test('auto-assign skips COI-conflicted bidders', function () {
    Notification::fake();

    $author = User::factory()->create([
        'email' => 'alice@univ.edu',
        'first_name' => 'Alice',
        'last_name' => 'Smith',
    ]);
    $chair = User::factory()->create();
    $conflicted = User::factory()->create([
        'email' => 'bob@univ.edu',
        'first_name' => 'Alice', // same name + shared domain => hard (score 5)
        'last_name' => 'Smith',
    ]);
    $clean = User::factory()->create([
        'email' => 'carl@other.edu',
        'first_name' => 'Carl',
        'last_name' => 'Lee',
    ]);

    // Review must already be open to staff reviewers (AD-012 / SD-05): bids are collected
    // between CFP close and review open, staffing happens after it. The pre-SD-05 auto-assign
    // path refused only after review_close_at, so this fixture never opened the window.
    $submission = makeBiddableSubmission($author, ['review_open_at' => now()->subDay()]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($submission->conference_id);
    $chair->assignRole('chair');
    $conflicted->assignRole('committee_member');
    $clean->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    SubmissionBid::create(['submission_id' => $submission->id, 'user_id' => $conflicted->id]);
    SubmissionBid::create(['submission_id' => $submission->id, 'user_id' => $clean->id]);

    $this->actingAs($chair)
        ->post(route('submissions.auto-assign', $submission))
        ->assertRedirect();

    $assigned = $submission->fresh()->assignments()->pluck('reviewer_id')->all();
    expect($assigned)->toContain($clean->id);
    expect($assigned)->not->toContain($conflicted->id);
});

test('auto-assign skips self-review bidders even with override', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();
    $clean = User::factory()->create();

    // Review must already be open to staff reviewers (AD-012 / SD-05): bids are collected
    // between CFP close and review open, staffing happens after it. The pre-SD-05 auto-assign
    // path refused only after review_close_at, so this fixture never opened the window.
    $submission = makeBiddableSubmission($author, ['review_open_at' => now()->subDay()]);
    $coAuthor = User::factory()->create();
    SubmissionCoAuthor::create([
        'submission_id' => $submission->id,
        'user_id' => $coAuthor->id,
        'first_name' => $coAuthor->first_name,
        'last_name' => $coAuthor->last_name,
        'email' => $coAuthor->email,
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($submission->conference_id);
    $chair->assignRole('chair');
    $clean->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // The co-author can't bid (own submission), so this tests the guard via
    // the self-review COI path on auto-assign with a co-author bid seeded.
    SubmissionBid::create(['submission_id' => $submission->id, 'user_id' => $coAuthor->id]);
    SubmissionBid::create(['submission_id' => $submission->id, 'user_id' => $clean->id]);

    $this->actingAs($chair)
        ->post(route('submissions.auto-assign', $submission), [
            'override_conflict' => 1,
        ])
        ->assertRedirect();

    $assigned = $submission->fresh()->assignments()->pluck('reviewer_id')->all();
    expect($assigned)->toContain($clean->id);
    expect($assigned)->not->toContain($coAuthor->id);
});

test('equality case: review_open_at == submission_close_at yields no biddable submissions', function () {
    $author = User::factory()->create();
    $committee = User::factory()->create();
    $submission = makeBiddableSubmission($author, [
        'submission_close_at' => now()->subDays(3),
        'review_open_at' => now()->subDays(3), // equal => zero-length bidding window
    ]);
    assignCommitteeRole($committee, $submission->conference);

    $this->actingAs($committee)
        ->post(route('submissions.bid', $submission))
        ->assertRedirect()
        ->assertSessionHasErrors(['submission']);

    $response = $this->actingAs($committee)->get(route('bidding.index'));
    $response->assertInertia(fn (Assert $page) => $page
        ->where('submissions', [])
    );
});

test('bidding stays closed for the whole day the CFP closes and opens the next day', function () {
    Notification::fake();

    $author = User::factory()->create();
    $committee = User::factory()->create();
    $submission = makeBiddableSubmission($author, [
        'submission_close_at' => now()->startOfDay(),
        'review_open_at' => now()->addDays(3),
    ]);
    assignCommitteeRole($committee, $submission->conference);

    // The closing day belongs to the CFP right up to midnight, so the last hour
    // of it is still not bidding. Bidding opens once that day is over.
    $this->travelTo(now()->endOfDay()->subHour());

    $this->actingAs($committee)
        ->post(route('submissions.bid', $submission))
        ->assertRedirect()
        ->assertSessionHasErrors(['submission']);

    expect(SubmissionBid::where('submission_id', $submission->id)
        ->where('user_id', $committee->id)->exists())->toBeFalse();

    $this->travelTo(now()->addDay()->startOfDay()->addHour());

    $this->actingAs($committee)
        ->post(route('submissions.bid', $submission))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect(SubmissionBid::where('submission_id', $submission->id)
        ->where('user_id', $committee->id)->exists())->toBeTrue();
});

test('double-blind bid list hides author and co-author identity', function () {
    $author = User::factory()->create();
    $committee = User::factory()->create();
    $submission = makeBiddableSubmission($author);
    $submission->conference->update(['double_blind_review' => true]);
    assignCommitteeRole($committee, $submission->conference);

    SubmissionCoAuthor::create([
        'submission_id' => $submission->id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
    ]);

    $response = $this->actingAs($committee)->get(route('bidding.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('submissions', 1)
        ->where('submissions.0.user', null)
        ->where('submissions.0.user_id', null)
        ->where('submissions.0.co_authors', [])
        ->where('submissions.0.title', $submission->title)
    );
});

test('committee member scoped to a later conference still sees its biddable submissions', function () {
    $author = User::factory()->create();
    $committee = User::factory()->create();

    // First conference (iterated first) where the committee member has NO role.
    $first = makeBiddableSubmission($author)->conference;

    // Second conference (iterated after) where the member holds the role.
    $conference = Conference::create([
        'title' => 'Later Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);
    $track = Track::create([
        'conference_id' => $conference->id,
        'name' => 'Main Track',
        'submission_open_at' => now()->subDays(10),
        'submission_close_at' => now()->subDays(5),
        'review_open_at' => now()->addDays(2),
        'review_close_at' => now()->addDays(10),
    ]);
    $submission = Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => $author->id,
        'title' => 'Later Paper',
        'abstract' => 'Test',
        'status' => SubmissionStatus::Submitted,
    ]);
    assignCommitteeRole($committee, $conference);

    $response = $this->actingAs($committee)->get(route('bidding.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('submissions', 1)
        ->where('submissions.0.title', 'Later Paper')
    );
});
