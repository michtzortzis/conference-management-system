<?php

use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Models\Affiliation;
use App\Models\Conference;
use App\Models\ReviewerConflict;
use App\Models\Submission;
use App\Models\SubmissionCoAuthor;
use App\Models\Track;
use App\Models\User;
use App\Services\ConflictOfInterestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeCoiConference(array $attributes = []): Conference
{
    return Conference::create(array_merge([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ], $attributes));
}

function makeCoiChair(Conference $conference): User
{
    $chair = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $chair;
}

function makeCoiCommitteeMember(Conference $conference, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $user->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $user;
}

function makeCoiAuthor(Conference $conference, array $attributes = []): User
{
    $author = User::factory()->create($attributes);
    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $author->assignRole('author');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $author;
}

function makeCoiSubmission(Conference $conference, User $author, ?Track $track = null): Submission
{
    return Submission::create([
        // A submission lives in a track: PhaseGuards (AD-012) derives review legality from
        // the track's boundary pair, so a trackless submission cannot be staffed at all.
        'track_id' => ($track ?? openReviewTrack($conference))->id,
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Test Paper',
        'abstract' => 'Test abstract',
        'status' => SubmissionStatus::Submitted,
    ]);
}

function attachCoiAffiliation(User $user, string $name, ?string $department = null): void
{
    $affiliation = Affiliation::firstOrCreate(
        ['name' => $name],
        ['type' => 'university']
    );
    $user->affiliations()->attach($affiliation->id, ['department' => $department]);
}

function addCoiCoAuthor(Submission $submission, string $email, ?User $registered = null, ?string $firstName = null, ?string $lastName = null): SubmissionCoAuthor
{
    return SubmissionCoAuthor::create([
        'submission_id' => $submission->id,
        'user_id' => $registered?->id,
        'first_name' => $firstName ?? 'Jane',
        'last_name' => $lastName ?? 'Doe',
        'email' => $email,
    ]);
}

function makeCoiBiddableTrack(Conference $conference): Track
{
    return $conference->tracks()->create([
        'name' => 'Main Track',
        'submission_open_at' => now()->subDays(10)->toDateString(),
        'submission_close_at' => now()->subDay()->toDateString(),
        'review_open_at' => now()->addDays(5)->toDateString(),
        'review_close_at' => now()->addDays(10)->toDateString(),
    ]);
}

test('declared conflict blocks assignment and can never be overridden', function () {
    Notification::fake();

    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $reviewer = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);

    app(ConflictOfInterestService::class)->recordDeclared(
        $submission,
        $reviewer,
        $author,
        null,
        'Personal relationship',
        $chair
    );

    $response = $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
            'override_conflict' => 1,
        ]);

    $response->assertRedirect();
    $response->assertSessionHasErrors(['reviewer_id']);
    expect($submission->fresh()->assignments()->count())->toBe(0);
});

test('email-exact match against a co-author auto-detects a hard conflict', function () {
    Notification::fake();

    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $reviewer = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);

    addCoiCoAuthor($submission, $reviewer->email);

    $response = $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
        ]);

    $response->assertSessionHasErrors(['reviewer_id']);
    expect($submission->fresh()->assignments()->count())->toBe(0);

    $conflict = ReviewerConflict::where('submission_id', $submission->id)
        ->where('reviewer_id', $reviewer->id)
        ->first();
    expect($conflict)->not->toBeNull();
    expect($conflict->level)->toBe('hard');
});

test('affiliation with matching department auto-detects a hard conflict', function () {
    Notification::fake();

    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $reviewer = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);

    attachCoiAffiliation($author, 'Acme University', 'CS');
    attachCoiAffiliation($reviewer, 'Acme University', 'CS');

    $response = $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
        ]);

    $response->assertSessionHasErrors(['reviewer_id']);
    expect($submission->fresh()->assignments()->count())->toBe(0);
});

test('chair can override a hard auto conflict and assign anyway', function () {
    Notification::fake();

    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $reviewer = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);

    attachCoiAffiliation($author, 'Acme University', 'CS');
    attachCoiAffiliation($reviewer, 'Acme University', 'CS');

    $response = $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
            'override_conflict' => 1,
        ]);

    $response->assertSessionHasNoErrors();
    expect($submission->fresh()->assignments()->count())->toBe(1);
    expect(ReviewerConflict::where('submission_id', $submission->id)
        ->where('reviewer_id', $reviewer->id)
        ->first()->overridden)->toBeTrue();
});

test('email-domain match auto-detects a soft conflict but still allows assignment', function () {
    Notification::fake();

    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference, ['email' => 'author@acme.edu']);
    $reviewer = makeCoiCommitteeMember($conference, ['email' => 'reviewer@acme.edu']);
    $submission = makeCoiSubmission($conference, $author);

    $response = $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
        ]);

    $response->assertSessionHasNoErrors();
    expect($submission->fresh()->assignments()->count())->toBe(1);

    $conflict = ReviewerConflict::where('submission_id', $submission->id)
        ->where('reviewer_id', $reviewer->id)
        ->first();
    expect($conflict->level)->toBe('soft');
});

test('free email domains contribute zero score and do not flag a conflict', function () {
    Notification::fake();

    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference, ['email' => 'author@gmail.com']);
    $reviewer = makeCoiCommitteeMember($conference, ['email' => 'reviewer@gmail.com']);
    $submission = makeCoiSubmission($conference, $author);

    $response = $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
        ]);

    $response->assertSessionHasNoErrors();
    expect($submission->fresh()->assignments()->count())->toBe(1);
    expect(ReviewerConflict::where('submission_id', $submission->id)
        ->where('reviewer_id', $reviewer->id)
        ->exists())->toBeFalse();
});

test('disabling coi_auto_detect skips auto-detection entirely', function () {
    Notification::fake();

    $conference = makeCoiConference(['coi_auto_detect' => false]);
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $reviewer = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);

    addCoiCoAuthor($submission, $reviewer->email);

    $response = $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
        ]);

    $response->assertSessionHasNoErrors();
    expect($submission->fresh()->assignments()->count())->toBe(1);
    expect(ReviewerConflict::where('submission_id', $submission->id)
        ->where('reviewer_id', $reviewer->id)
        ->exists())->toBeFalse();
});

test('self-review: the submitter can never be assigned as reviewer', function () {
    Notification::fake();

    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);

    // The author is also a committee member, but must never review their own paper.
    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $author->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $submission = makeCoiSubmission($conference, $author);

    $response = $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $author->id,
            'override_conflict' => 1,
        ]);

    $response->assertSessionHasErrors(['reviewer_id']);
    expect($submission->fresh()->assignments()->count())->toBe(0);
});

test('self-review: a registered co-author can never be assigned as reviewer', function () {
    Notification::fake();

    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $coAuthor = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);

    addCoiCoAuthor($submission, $coAuthor->email, $coAuthor, $coAuthor->first_name, $coAuthor->last_name);

    $response = $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $coAuthor->id,
            'override_conflict' => 1,
        ]);

    $response->assertSessionHasErrors(['reviewer_id']);
    expect($submission->fresh()->assignments()->count())->toBe(0);
});

test('adding an existing reviewer as a co-author flags a self-review conflict', function () {
    Notification::fake();

    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $reviewer = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);

    $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
        ])
        ->assertSessionHasNoErrors();

    // Assigning the reviewer already moved the paper to under_review (AD-009),
    // so the author can no longer edit it; the chair registers the co-author.
    $this->actingAs($chair)->put(route('submissions.update', $submission), [
        'title' => 'Test Paper',
        'abstract' => 'Test abstract',
        'co_authors' => [[
            'first_name' => $reviewer->first_name,
            'last_name' => $reviewer->last_name,
            'email' => $reviewer->email,
        ]],
    ])->assertRedirect();

    // A chair edit does not recall the paper, so it stays under review and no
    // re-submit step is needed before the re-invite attempt below.
    expect($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);

    // Re-inviting them now must be blocked as a self-review.
    $response = $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
            'override_conflict' => 1,
        ]);

    $response->assertSessionHasErrors(['reviewer_id']);
});

test('chair can declare a conflict against a specific author', function () {
    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $reviewer = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);

    $this->actingAs($chair)
        ->post(route('submissions.conflicts.store', $submission), [
            'reviewer_id' => $reviewer->id,
            'author_id' => $author->id,
            'reason' => 'Known collaborator',
        ])
        ->assertSessionHasNoErrors();

    $conflict = ReviewerConflict::where('submission_id', $submission->id)
        ->where('reviewer_id', $reviewer->id)
        ->first();
    expect($conflict)->not->toBeNull();
    expect($conflict->level)->toBe('declared');
    expect($conflict->author_id)->toBe($author->id);
    expect($conflict->reason)->toBe('Known collaborator');
});

test('override endpoint overrides an auto conflict but rejects a declared one', function () {
    Notification::fake();

    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $reviewer = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);

    attachCoiAffiliation($author, 'Acme University', 'CS');
    attachCoiAffiliation($reviewer, 'Acme University', 'CS');

    // Auto hard conflict exists.
    $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
        ])
        ->assertSessionHasErrors(['reviewer_id']);

    $this->actingAs($chair)
        ->post(route('submissions.conflicts.override', [$submission, $reviewer]))
        ->assertSessionHasNoErrors();

    expect(ReviewerConflict::where('submission_id', $submission->id)
        ->where('reviewer_id', $reviewer->id)
        ->first()->overridden)->toBeTrue();

    // Declared conflicts cannot be overridden.
    app(ConflictOfInterestService::class)->recordDeclared(
        $submission,
        $reviewer,
        $author,
        null,
        'Known collaborator',
        $chair
    );

    $this->actingAs($chair)
        ->post(route('submissions.conflicts.override', [$submission, $reviewer]))
        ->assertSessionHasErrors(['reviewer_id']);
});

test('chair can toggle coi_auto_detect via the conference create form and it persists', function () {
    $chair = User::factory()->create();

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $chair->assignRole('organizer');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $this->actingAs($chair)
        ->post(route('conferences.store'), [
            'title' => 'Coi Conference',
            'description' => 'Test',
            'coi_auto_detect' => 0,
            'tracks' => [[
                'name' => 'Main Track',
                'submission_open_at' => now()->subDay()->toDateString(),
                'submission_close_at' => now()->addDays(30)->toDateString(),
            ]],
        ])
        ->assertRedirect(route('conferences.index'));

    $conference = Conference::where('title', 'Coi Conference')->first();
    expect($conference)->not->toBeNull();
    expect($conference->coi_auto_detect)->toBeFalse();
});

test('coi_auto_detect defaults to true on a new conference', function () {
    $conference = Conference::create([
        'title' => 'Default Conference',
        'description' => 'Test',
    ]);

    expect($conference->fresh()->coi_auto_detect)->toBeTrue();
});

test('reviewer can self-declare a conflict from the assignment list and it withdraws the assignment', function () {
    Notification::fake();

    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $reviewer = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);

    $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
        ])
        ->assertSessionHasNoErrors();

    $assignment = $submission->assignments()->first();

    $response = $this->actingAs($reviewer)
        ->post(route('reviews.self-conflict', $assignment), [
            'reason' => 'Personal relationship',
        ]);

    $response->assertSessionHasNoErrors();

    $conflict = ReviewerConflict::where('submission_id', $submission->id)
        ->where('reviewer_id', $reviewer->id)
        ->first();
    expect($conflict)->not->toBeNull();
    expect($conflict->level)->toBe('declared');
    expect($conflict->reason)->toBe('Personal relationship');
    expect($conflict->author_id)->toBeNull();

    expect($assignment->fresh()->status)->toBe(ReviewAssignmentStatus::Declined);
});

test('a reviewer cannot self-declare a conflict on another reviewer assignment', function () {
    Notification::fake();

    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $reviewer = makeCoiCommitteeMember($conference);
    $other = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);

    $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
        ])
        ->assertSessionHasNoErrors();

    $assignment = $submission->assignments()->first();

    $this->actingAs($other)
        ->post(route('reviews.self-conflict', $assignment))
        ->assertForbidden();
});

test('a declared conflict blocks bidding on a submission', function () {
    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $reviewer = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);
    $track = makeCoiBiddableTrack($conference);
    $submission->track_id = $track->id;
    $submission->save();

    app(ConflictOfInterestService::class)->recordDeclared(
        $submission,
        $reviewer,
        $author,
        null,
        'Known collaborator',
        $chair
    );

    $this->actingAs($reviewer)
        ->post(route('submissions.bid', $submission))
        ->assertRedirect()
        ->assertSessionHasErrors(['submission']);

    expect($submission->bids()->count())->toBe(0);
});

test('email-exact match blocks bidding as an auto hard conflict', function () {
    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $reviewer = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);
    $track = makeCoiBiddableTrack($conference);
    $submission->track_id = $track->id;
    $submission->save();

    addCoiCoAuthor($submission, $reviewer->email);

    $this->actingAs($reviewer)
        ->post(route('submissions.bid', $submission))
        ->assertRedirect()
        ->assertSessionHasErrors(['submission']);

    expect($submission->bids()->count())->toBe(0);
});

test('email-domain match still allows bidding and flags a soft conflict', function () {
    $conference = makeCoiConference();
    $author = makeCoiAuthor($conference, ['email' => 'author@acme.edu']);
    $reviewer = makeCoiCommitteeMember($conference, ['email' => 'reviewer@acme.edu']);
    $submission = makeCoiSubmission($conference, $author);
    $track = makeCoiBiddableTrack($conference);
    $submission->track_id = $track->id;
    $submission->save();

    $this->actingAs($reviewer)
        ->post(route('submissions.bid', $submission))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($submission->bids()->count())->toBe(1);
});

test('a chair-overridden hard conflict allows the reviewer to bid', function () {
    $conference = makeCoiConference();
    $chair = makeCoiChair($conference);
    $author = makeCoiAuthor($conference);
    $reviewer = makeCoiCommitteeMember($conference);
    $submission = makeCoiSubmission($conference, $author);
    $track = makeCoiBiddableTrack($conference);
    $submission->track_id = $track->id;
    $submission->save();

    addCoiCoAuthor($submission, $reviewer->email);

    $this->actingAs($chair)
        ->post(route('submissions.conflicts.override', [$submission, $reviewer]))
        ->assertSessionHasNoErrors();

    $this->actingAs($reviewer)
        ->post(route('submissions.bid', $submission))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($submission->bids()->count())->toBe(1);
});

test('double-blind bid list never exposes the auto conflict reason', function () {
    $conference = makeCoiConference(['double_blind_review' => true]);
    $author = makeCoiAuthor($conference, ['email' => 'author@acme.edu']);
    $reviewer = makeCoiCommitteeMember($conference, ['email' => 'reviewer@acme.edu']);
    $submission = makeCoiSubmission($conference, $author);
    $track = makeCoiBiddableTrack($conference);
    $submission->track_id = $track->id;
    $submission->save();

    $response = $this->actingAs($reviewer)->get(route('bidding.index'));

    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->has('submissions', 1)
        ->where('coi_flags.'.$submission->id.'.auto_level', 'soft')
        ->where('coi_flags.'.$submission->id.'.auto_reason', null)
    );
});
