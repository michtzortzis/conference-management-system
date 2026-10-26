<?php

use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\SubmissionCoAuthor;
use App\Models\Track;
use App\Models\User;
use App\Notifications\CoAuthorInvitation;
use App\Notifications\DecisionMade;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function makeConference(): Conference
{
    return Conference::create([
        'title' => 'Test Conference',
        'description' => 'Test description',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(2)->toDateString(),
    ]);
}

function makeOpenTrack(Conference $conference): Track
{
    return Track::create([
        'conference_id' => $conference->id,
        'name' => 'Main Track',
    ]);
}

function makeGlobalAuthor(): User
{
    $author = User::factory()->create();
    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $author->assignRole('author');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    return $author;
}

test('author can create a submission with registered and unregistered co-authors', function () {
    Notification::fake();

    $author = makeGlobalAuthor();
    $registeredCoAuthor = User::factory()->create(['email' => 'regco@example.com']);
    $conference = makeConference();
    $track = makeOpenTrack($conference);

    $this->actingAs($author)->post(route('submissions.store'), [
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'title' => 'Co-authored paper',
        'abstract' => 'Abstract',
        'co_authors' => [
            [
                'first_name' => $registeredCoAuthor->first_name,
                'last_name' => $registeredCoAuthor->last_name,
                'email' => $registeredCoAuthor->email,
            ],
            [
                'first_name' => 'Jane',
                'last_name' => 'Doe',
                'email' => 'jane@example.com',
            ],
        ],
    ])->assertRedirect();

    $submission = Submission::first();
    expect($submission->coAuthors()->count())->toBe(2);

    $registered = $submission->coAuthors()->where('email', $registeredCoAuthor->email)->first();
    expect($registered->user_id)->toBe($registeredCoAuthor->id);

    $unregistered = $submission->coAuthors()->where('email', 'jane@example.com')->first();
    expect($unregistered->user_id)->toBeNull();
    expect($unregistered->invitation_sent_at)->not->toBeNull();
    Notification::assertSentTo(
        new AnonymousNotifiable,
        CoAuthorInvitation::class
    );
});

test('unregistered co-author receives an invitation notification', function () {
    Notification::fake();

    $author = makeGlobalAuthor();
    $conference = makeConference();
    $track = makeOpenTrack($conference);

    $this->actingAs($author)->post(route('submissions.store'), [
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'title' => 'Paper',
        'abstract' => 'Abstract',
        'co_authors' => [
            ['first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com'],
        ],
    ])->assertRedirect();

    $notifiable = new AnonymousNotifiable;
    $notifiable->routes = ['mail' => 'jane@example.com'];

    Notification::assertSentTo($notifiable, CoAuthorInvitation::class);
});

test('author can sync co-authors when updating a submission', function () {
    Notification::fake();

    $author = makeGlobalAuthor();
    $conference = makeConference();
    $track = makeOpenTrack($conference);

    $submission = Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => $author->id,
        'title' => 'Paper',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::Draft,
    ]);

    $co1 = SubmissionCoAuthor::create([
        'submission_id' => $submission->id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
    ]);

    $co2 = SubmissionCoAuthor::create([
        'submission_id' => $submission->id,
        'first_name' => 'Bob',
        'last_name' => 'Smith',
        'email' => 'bob@example.com',
    ]);

    $this->actingAs($author)->put(route('submissions.update', $submission), [
        'title' => 'Updated paper',
        'abstract' => 'New abstract',
        'co_authors' => [
            ['id' => $co1->id, 'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'jane@example.com'],
            ['first_name' => 'Alice', 'last_name' => 'Jones', 'email' => 'alice@example.com'],
        ],
    ])->assertRedirect();

    $fresh = $submission->fresh();
    expect($fresh->coAuthors()->count())->toBe(2);
    expect($fresh->coAuthors()->where('id', $co2->id)->exists())->toBeFalse();
    expect($fresh->coAuthors()->where('email', 'alice@example.com')->exists())->toBeTrue();
});

test('registered co-author can view the submission they are a co-author of', function () {
    $author = makeGlobalAuthor();
    $coAuthor = User::factory()->create(['email' => 'co@example.com']);
    $conference = makeConference();

    $submission = Submission::create([
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Paper',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    SubmissionCoAuthor::create([
        'submission_id' => $submission->id,
        'user_id' => $coAuthor->id,
        'first_name' => $coAuthor->first_name,
        'last_name' => $coAuthor->last_name,
        'email' => $coAuthor->email,
    ]);

    $response = $this->actingAs($coAuthor)->get(route('submissions.show', $submission));
    $response->assertStatus(200);
    $response->assertInertia(fn (Assert $page) => $page
        ->where('viewer_role', 'author')
        ->has('submission.co_authors', 1)
    );
});

test('co-author cannot update, submit, or delete the submission', function () {
    $author = makeGlobalAuthor();
    $coAuthor = User::factory()->create(['email' => 'co@example.com']);
    $conference = makeConference();

    $submission = Submission::create([
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Paper',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::Draft,
    ]);

    SubmissionCoAuthor::create([
        'submission_id' => $submission->id,
        'user_id' => $coAuthor->id,
        'first_name' => $coAuthor->first_name,
        'last_name' => $coAuthor->last_name,
        'email' => $coAuthor->email,
    ]);

    $this->actingAs($coAuthor)->put(route('submissions.update', $submission), [
        'title' => 'Hacked',
        'abstract' => 'Hacked',
    ])->assertForbidden();

    $this->actingAs($coAuthor)->post(route('submissions.submit', $submission))
        ->assertForbidden();

    $this->actingAs($coAuthor)->delete(route('submissions.destroy', $submission))
        ->assertForbidden();
});

test('decision notifies the main author and every registered co-author', function () {
    Notification::fake();

    $chair = User::factory()->create();
    $author = makeGlobalAuthor();
    $coAuthor1 = User::factory()->create(['email' => 'co1@example.com']);
    $coAuthor2 = User::factory()->create(['email' => 'co2@example.com']);

    $conference = makeConference();
    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $submission = Submission::create([
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Paper',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::UnderReview,
    ]);

    SubmissionCoAuthor::create([
        'submission_id' => $submission->id,
        'user_id' => $coAuthor1->id,
        'first_name' => $coAuthor1->first_name,
        'last_name' => $coAuthor1->last_name,
        'email' => $coAuthor1->email,
    ]);

    // Unregistered co-author gets no notification (no user record)
    SubmissionCoAuthor::create([
        'submission_id' => $submission->id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
    ]);

    $this->actingAs($chair)
        ->from(route('submissions.show', $submission))
        ->post(route('submissions.decision.store', $submission), [
            'decision' => 'accepted',
        ])->assertRedirect();

    Notification::assertSentTo($author, DecisionMade::class);
    Notification::assertSentTo($coAuthor1, DecisionMade::class);
    Notification::assertNotSentTo($coAuthor2, DecisionMade::class);
});

test('registering with a matching email claims co-authorship', function () {
    $author = makeGlobalAuthor();
    $conference = makeConference();

    $submission = Submission::create([
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Paper',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::UnderReview,
    ]);

    $invitation = SubmissionCoAuthor::create([
        'submission_id' => $submission->id,
        'first_name' => 'Invited',
        'last_name' => 'Author',
        'email' => 'invited@example.com',
    ]);

    // The unregistered co-author now registers with the invited email.
    $this->post(route('register.store'), [
        'first_name' => 'Invited',
        'last_name' => 'Author',
        'email' => 'invited@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ])->assertRedirect(route('dashboard'));

    $newUser = User::where('email', 'invited@example.com')->firstOrFail();
    $invitation->refresh();
    expect($invitation->user_id)->toBe($newUser->id);
    expect($invitation->invitation_accepted_at)->not->toBeNull();
});

test('adding an existing reviewer as a co-author invalidates their self-review assignment', function () {
    Notification::fake();

    $author = makeGlobalAuthor();
    $reviewer = User::factory()->create(['email' => 'reviewer@example.com']);
    $conference = makeConference();

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $reviewer->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $submission = Submission::create([
        'conference_id' => $conference->id,
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

    // The main author adds the reviewer (already registered) as a co-author.
    $this->actingAs($author)->put(route('submissions.update', $submission), [
        'title' => 'Paper',
        'abstract' => 'Abstract',
        'co_authors' => [
            [
                'first_name' => $reviewer->first_name,
                'last_name' => $reviewer->last_name,
                'email' => $reviewer->email,
            ],
        ],
    ])->assertRedirect();

    $assignment->refresh();
    expect($assignment->status)->toBe(ReviewAssignmentStatus::Declined);
});

test('a pending self-review assignment is also invalidated when a reviewer becomes a co-author', function () {
    Notification::fake();

    $author = makeGlobalAuthor();
    $reviewer = User::factory()->create(['email' => 'reviewer@example.com']);
    $conference = makeConference();

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $reviewer->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $submission = Submission::create([
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Paper',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Pending,
        'assigned_at' => now(),
    ]);

    $this->actingAs($author)->put(route('submissions.update', $submission), [
        'title' => 'Paper',
        'abstract' => 'Abstract',
        'co_authors' => [
            [
                'first_name' => $reviewer->first_name,
                'last_name' => $reviewer->last_name,
                'email' => $reviewer->email,
            ],
        ],
    ])->assertRedirect();

    $assignment->refresh();
    expect($assignment->status)->toBe(ReviewAssignmentStatus::Declined);
});

test('a co-author cannot accept a review assignment on their own submission', function () {
    Notification::fake();

    $author = makeGlobalAuthor();
    $reviewer = User::factory()->create(['email' => 'reviewer@example.com']);
    $conference = makeConference();

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $reviewer->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $submission = Submission::create([
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Paper',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    $assignment = ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Pending,
        'assigned_at' => now(),
    ]);

    SubmissionCoAuthor::create([
        'submission_id' => $submission->id,
        'user_id' => $reviewer->id,
        'first_name' => $reviewer->first_name,
        'last_name' => $reviewer->last_name,
        'email' => $reviewer->email,
    ]);

    $this->actingAs($reviewer)->post(route('reviews.accept', $assignment))
        ->assertForbidden();

    $assignment->refresh();
    expect($assignment->status)->toBe(ReviewAssignmentStatus::Pending);
});

test('changing an unregistered co-author email resends the invitation to the new address', function () {
    Notification::fake();

    $author = makeGlobalAuthor();
    $conference = makeConference();
    $track = makeOpenTrack($conference);

    $submission = Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => $author->id,
        'title' => 'Paper',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::Draft,
    ]);

    $co = SubmissionCoAuthor::create([
        'submission_id' => $submission->id,
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'email' => 'jane@example.com',
        'invitation_sent_at' => now(),
    ]);

    $this->actingAs($author)->put(route('submissions.update', $submission), [
        'title' => 'Paper',
        'abstract' => 'Abstract',
        'co_authors' => [
            ['id' => $co->id, 'first_name' => 'Jane', 'last_name' => 'Doe', 'email' => 'new@example.com'],
        ],
    ])->assertRedirect();

    $co->refresh();
    expect($co->email)->toBe('new@example.com');
    expect($co->invitation_sent_at)->not->toBeNull();

    $notifiable = new AnonymousNotifiable;
    $notifiable->routes = ['mail' => 'new@example.com'];
    Notification::assertSentTo($notifiable, CoAuthorInvitation::class);
});

test('index lists co-authored submissions for the co-author', function () {
    $author = makeGlobalAuthor();
    $coAuthor = makeGlobalAuthor();
    $conference = makeConference();

    $own = Submission::create([
        'conference_id' => $conference->id,
        'user_id' => $coAuthor->id,
        'title' => 'Own paper',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    $shared = Submission::create([
        'conference_id' => $conference->id,
        'user_id' => $author->id,
        'title' => 'Shared paper',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    SubmissionCoAuthor::create([
        'submission_id' => $shared->id,
        'user_id' => $coAuthor->id,
        'first_name' => $coAuthor->first_name,
        'last_name' => $coAuthor->last_name,
        'email' => $coAuthor->email,
    ]);

    $response = $this->actingAs($coAuthor)->get(route('submissions.index'));

    $response->assertInertia(fn (Assert $page) => $page
        ->has('submissions', 2)
        ->has('submissions', fn (Assert $list) => $list
            ->where('0.id', $own->id)
            ->where('1.id', $shared->id)
        )
    );
});
