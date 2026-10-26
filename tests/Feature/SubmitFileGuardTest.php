<?php

use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * D-7.1 — Fig 3.7 draws `Draft --> Submitted : submit for review ⟨CFP open, ≥1 file⟩`.
 * Until this landed the `≥1 file` half was fiction: `SubmissionPolicy::submit` checked
 * ownership + Draft and `submit()` re-checked only the CFP window, so an empty draft could
 * enter review and chairs would open a paper with nothing to read.
 */
function draftForUpload(): Submission
{
    $author = User::factory()->create();
    $conference = Conference::create([
        'title' => 'Upload Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(30)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $author->assignRole('author');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    // CFP must be OPEN here: this file is about the file guard, and `submit()` also
    // re-checks the CFP window (Fig 3.7's `CFP open` half of the guard).
    $track = openReviewTrack($conference, 'Main Track', [
        'submission_close_at' => now()->addDays(5),
        'review_open_at' => now()->addDays(6),
        'review_close_at' => now()->addDays(12),
    ]);

    return Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => $author->id,
        'title' => 'PAPER WITHOUT A FILE',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::Draft,
    ]);
}

test('a draft with no file cannot be submitted for review', function () {
    $submission = draftForUpload();

    // A policy refusal is a 403, not a redirect with flash — the guard lives in
    // SubmissionPolicy::submit, which is exactly where Fig 3.7 draws it.
    $this->actingAs($submission->user)
        ->post(route('submissions.submit', $submission))
        ->assertForbidden();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Draft);
});

test('the same draft enters review once it carries one file', function () {
    $submission = draftForUpload();

    attachFileToSubmission($submission);

    $this->actingAs($submission->user)
        ->post(route('submissions.submit', $submission))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($submission->fresh()->status)->toBe(SubmissionStatus::Submitted);
});
