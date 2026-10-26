<?php

use App\Enums\Phase;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\SubmissionBid;
use App\Models\Track;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

/**
 * D-4.4 — Fig 3.4's `V2 Place / withdraw bid`.
 *
 * `destroy()` deleted any bid of the current user with no window and no membership check:
 * the UI hid the button, the route accepted the write. A withdrawn bid is also
 * irreversible (nothing re-creates it), so a post-close withdrawal silently destroyed the
 * chair's ranking input. Bidding window here is deliberately OPEN: CFP closed yesterday,
 * review opens in five days.
 */
function biddableSetup(): array
{
    $author = User::factory()->create();
    $bidder = User::factory()->create();
    $outsider = User::factory()->create();

    $conference = Conference::create([
        'title' => 'Bidding Window Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(30)->toDateString(),
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $author->assignRole('author');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $bidder->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $track = Track::create([
        'conference_id' => $conference->id,
        'name' => 'Main Track',
        'submission_open_at' => now()->subDays(10),
        'submission_close_at' => now()->subDay(),
        'review_open_at' => now()->addDays(5),
        'review_close_at' => now()->addDays(12),
    ]);

    $submission = Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => $author->id,
        'title' => 'Biddable Paper',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::Submitted,
    ]);

    SubmissionBid::create(['submission_id' => $submission->id, 'user_id' => $bidder->id]);

    return compact('conference', 'track', 'submission', 'bidder', 'outsider');
}

test('a bid can be withdrawn while the derived bidding window is open', function () {
    ['submission' => $submission, 'bidder' => $bidder] = biddableSetup();

    $this->actingAs($bidder)
        ->delete(route('submissions.unbid', $submission))
        ->assertRedirect()
        ->assertSessionHasNoErrors();

    expect($submission->bids()->count())->toBe(0);
});

test('a bid cannot be withdrawn once the bidding window has closed', function () {
    ['submission' => $submission, 'track' => $track, 'bidder' => $bidder] = biddableSetup();

    // Review opening closes bidding (Bidding window shares the review boundary).
    $this->travelTo($track->phaseOpensAt(Phase::Review)->addHour());

    $this->actingAs($bidder)
        ->delete(route('submissions.unbid', $submission))
        ->assertRedirect()
        ->assertSessionHasErrors('submission');

    $this->travelBack();

    expect($submission->bids()->count())->toBe(1);
});

test('a verified user who is not on the committee cannot withdraw someone else\'s bid', function () {
    ['submission' => $submission, 'outsider' => $outsider] = biddableSetup();

    $this->actingAs($outsider)
        ->delete(route('submissions.unbid', $submission))
        ->assertRedirect()
        ->assertSessionHasErrors('submission');

    expect($submission->bids()->count())->toBe(1);
});
