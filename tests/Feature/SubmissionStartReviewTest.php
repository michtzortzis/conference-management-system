<?php

use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\SubmissionBid;
use App\Models\User;
use App\Services\ConflictOfInterestService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('assigning a reviewer promotes a submitted submission to under_review', function () {
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

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
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

    expect($submission->status)->toBe(SubmissionStatus::Submitted);

    $this->actingAs($chair)
        ->post(route('submissions.assign-reviewer', $submission), [
            'reviewer_id' => $reviewer->id,
        ])
        ->assertRedirect();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);
});

test('auto-assigning bidders promotes a submitted submission to under_review', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();
    $bidder = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    $bidder->assignRole('committee_member');
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

    SubmissionBid::create(['submission_id' => $submission->id, 'user_id' => $bidder->id]);

    $this->actingAs($chair)
        ->post(route('submissions.auto-assign', $submission))
        ->assertRedirect();

    expect($submission->fresh()->assignments()->count())->toBe(1);
    expect($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);
});

test('auto-assign does not promote the submission when no bidder is assignable', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();
    $bidder = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    $bidder->assignRole('committee_member');
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

    SubmissionBid::create(['submission_id' => $submission->id, 'user_id' => $bidder->id]);

    // A declared conflict is authoritative, so the only bidder is skipped and
    // nothing is assigned.
    app(ConflictOfInterestService::class)->recordDeclared(
        $submission,
        $bidder,
        $author,
        null,
        'Personal relationship',
        $chair
    );

    $response = $this->actingAs($chair)
        ->from(route('submissions.show', $submission))
        ->post(route('submissions.auto-assign', $submission));

    $response->assertRedirect();
    $response->assertSessionHas('warning');

    // The promotion sits after the "nothing assigned" early return, so a
    // zero-assignment run must leave the paper exactly where it was (AD-009).
    expect($submission->fresh()->assignments()->count())->toBe(0);
    expect($submission->fresh()->status)->toBe(SubmissionStatus::Submitted);
});

test('chair can move a submitted submission into review without reviewers', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
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
        'status' => SubmissionStatus::Submitted,
    ]);

    $response = $this->actingAs($chair)
        ->from(route('submissions.show', $submission))
        ->post(route('submissions.start-review', $submission));

    $response->assertRedirect();
    $response->assertSessionHas('success');
    $response->assertSessionHasNoErrors();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);
});

test('global admin can move a submitted submission into review', function () {
    Notification::fake();

    $author = User::factory()->create();
    $admin = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    // Global roles live on team_id 0 (TestUserSeeder::assignGlobalRole). The
    // admin gets here from Gate::before (AppServiceProvider), which
    // short-circuits SubmissionPolicy::startReview — deliberately, the policy
    // carries no hasGlobalRole check.
    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $admin->assignRole('admin');
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

    $response = $this->actingAs($admin)
        ->from(route('submissions.show', $submission))
        ->post(route('submissions.start-review', $submission));

    $response->assertRedirect();
    $response->assertSessionHas('success');
    $response->assertSessionHasNoErrors();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);
});

test('start-review is forbidden for anyone who is not a chair of that conference', function () {
    Notification::fake();

    $author = User::factory()->create();
    $committeeMember = User::factory()->create();
    $plainUser = User::factory()->create();
    $foreignChair = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    $otherConference = Conference::create([
        'title' => 'Other Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $committeeMember->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    app(PermissionRegistrar::class)->setPermissionsTeamId($otherConference->id);
    $foreignChair->assignRole('chair');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Track with an OPEN review window: PhaseGuards (AD-012) derives legality
    // from the boundary pair, so a trackless submission cannot be staffed or reviewed.
    $track = openReviewTrack($otherConference);

    $submission = Submission::create([
        'track_id' => $track->id,
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Test Paper',
        'abstract' => 'Test abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    foreach ([$committeeMember, $author, $plainUser, $foreignChair] as $user) {
        $this->actingAs($user)
            ->post(route('submissions.start-review', $submission))
            ->assertForbidden();
    }

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Submitted);
});

test('start-review is forbidden once the submission is already under review', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
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
        'status' => SubmissionStatus::UnderReview,
    ]);

    $this->actingAs($chair)
        ->post(route('submissions.start-review', $submission))
        ->assertForbidden();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);
});

test('start-review is forbidden on a draft submission', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
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
        'status' => SubmissionStatus::Draft,
    ]);

    $this->actingAs($chair)
        ->post(route('submissions.start-review', $submission))
        ->assertForbidden();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Draft);
});

test('decision is reachable straight after a route-driven start-review', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // Track with an OPEN review window: PhaseGuards (AD-012) derives legality
    // from the boundary pair, so a trackless submission cannot be staffed or reviewed.
    // Review has run and closed; the decision window is open — the state this test's
    // last hop needs. PhaseGuards (AD-012) derives every window from the track, so the
    // fixture has to spell them out instead of relying on trackless checks being skipped.
    $track = openReviewTrack($conference, 'Main Track', [
        'review_close_at' => now()->subDay(),
        'decision_open_at' => now()->subDay(),
        'decision_close_at' => now()->addDays(5),
    ]);

    $submission = Submission::create([
        'track_id' => $track->id,
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Test Paper',
        'abstract' => 'Test abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    $this->actingAs($chair)
        ->post(route('submissions.start-review', $submission))
        ->assertRedirect();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);

    $response = $this->actingAs($chair)
        ->from(route('submissions.show', $submission))
        ->post(route('submissions.decision.store', $submission), [
            'decision' => 'accepted',
            'rationale' => 'Strong consensus',
        ]);

    $response->assertRedirect();
    $response->assertSessionHas('success');
    $response->assertSessionHasNoErrors();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Accepted);
});

test('author can no longer update the submission once it is under review', function () {
    Notification::fake();

    $author = User::factory()->create();
    $chair = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
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
        'status' => SubmissionStatus::Submitted,
    ]);

    $this->actingAs($chair)
        ->post(route('submissions.start-review', $submission))
        ->assertRedirect();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);

    $this->actingAs($author)
        ->from(route('submissions.edit', $submission))
        ->patch(route('submissions.update', $submission), [
            'title' => 'Edited After Review Started',
            'abstract' => 'Edited abstract',
        ])
        ->assertForbidden();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::UnderReview);
    expect($submission->fresh()->title)->toBe('Test Paper');
    expect($submission->fresh()->abstract)->toBe('Test abstract');
});

test('author editing a submitted submission demotes it back to draft', function () {
    Notification::fake();

    $author = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(3)->toDateString(),
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

    $this->actingAs($author)
        ->patch(route('submissions.update', $submission), [
            'title' => 'Edited While Submitted',
            'abstract' => 'Test abstract',
        ])
        ->assertRedirect();

    expect($submission->fresh()->title)->toBe('Edited While Submitted');
    expect($submission->fresh()->status)->toBe(SubmissionStatus::Draft);
});
