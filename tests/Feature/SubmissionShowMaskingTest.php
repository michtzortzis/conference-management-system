<?php

use App\Enums\ReviewAssignmentStatus;
use App\Enums\ReviewRecommendation;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Review;
use App\Models\ReviewAssignment;
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

/**
 * D-11.1 / IN-13 — the anonymisation lived in the Vue page, not in the payload.
 *
 * `SubmissionController::show()` shipped `committee_members` (names AND e-mails),
 * `coi_flags` (with the COI reason), `bidder_ids` and every `reviews.reviewer` to every
 * authorised viewer — including the author of a double-blind paper, whose page rendered
 * none of it and whose browser devtools showed all of it. Masking is now server-side and
 * keyed off the role that was already computed.
 */
function doubleBlindShowFixture(): array
{
    $author = User::factory()->create(['email' => 'author@example.org']);
    $chair = User::factory()->create(['email' => 'chair@example.org']);
    $reviewer = User::factory()->create(['email' => 'reviewer@example.org']);
    $other = User::factory()->create(['email' => 'other-committee@example.org']);

    $conference = Conference::create([
        'title' => 'Confidentiality Conference',
        'description' => 'Test',
        'start_date' => now()->toDateString(),
        'end_date' => now()->addDays(30)->toDateString(),
        'double_blind_review' => true,
    ]);

    app(PermissionRegistrar::class)->setPermissionsTeamId(0);
    $author->assignRole('author');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
    $chair->assignRole('chair');
    $reviewer->assignRole('committee_member');
    $other->assignRole('committee_member');
    app(PermissionRegistrar::class)->setPermissionsTeamId(null);

    $track = openReviewTrack($conference);

    $submission = Submission::create([
        'conference_id' => $conference->id,
        'track_id' => $track->id,
        'user_id' => $author->id,
        'title' => 'Sealed Paper',
        'abstract' => 'Abstract',
        'status' => SubmissionStatus::UnderReview,
    ]);

    ReviewAssignment::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'status' => ReviewAssignmentStatus::Accepted,
        'assigned_at' => now(),
        'accepted_at' => now(),
    ]);

    Review::create([
        'submission_id' => $submission->id,
        'reviewer_id' => $reviewer->id,
        'assignment_id' => $submission->assignments()->value('id'),
        'recommendation' => ReviewRecommendation::Accept,
        'grade' => 5,
        'comments' => 'Strong paper.',
    ]);

    SubmissionBid::create(['submission_id' => $submission->id, 'user_id' => $other->id]);

    return compact('conference', 'submission', 'author', 'chair', 'reviewer', 'other');
}

test('the author of a double-blind submission receives no committee identity, no COI flags and no bidder ids', function () {
    ['submission' => $submission, 'author' => $author, 'other' => $other] = doubleBlindShowFixture();

    $props = $this->actingAs($author)
        ->get(route('submissions.show', $submission))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['viewer_role'])->toBe('author')
        ->and($props['committee_members'])->toBe([])
        ->and($props['coi_flags'])->toBe([])
        ->and($props['bidder_ids'])->toBe([])
        // The reviewer identity must be gone from the payload itself, not just from the page.
        ->and(data_get($props, 'reviews.0.reviewer'))->toBeNull()
        // and the author must still see their own paper.
        ->and($props['submission']['title'])->toBe('Sealed Paper')
        ->and(json_encode($props))->not->toMatch('/'.preg_quote($other->email, '/').'/');
});

test('a committee reviewer still receives the committee roster it needs to declare a conflict', function () {
    ['submission' => $submission, 'reviewer' => $reviewer] = doubleBlindShowFixture();

    $props = $this->actingAs($reviewer)
        ->get(route('submissions.show', $submission))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['committee_members'])->not->toBe([])
        ->and($props['bidder_ids'])->not->toBe([]);
});

test('the chair keeps identities, COI flags and bidder ids', function () {
    ['submission' => $submission, 'chair' => $chair, 'reviewer' => $reviewer] = doubleBlindShowFixture();

    $props = $this->actingAs($chair)
        ->get(route('submissions.show', $submission))
        ->assertOk()
        ->viewData('page')['props'];

    expect($props['viewer_role'])->toBe('chair')
        ->and(count($props['committee_members']))->toBeGreaterThanOrEqual(2)
        ->and($props['bidder_ids'])->not->toBe([])
        ->and(json_encode($props))->toMatch('/'.preg_quote($reviewer->email, '/').'/');
});
