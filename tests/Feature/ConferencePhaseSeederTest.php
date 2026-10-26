<?php

use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use Database\Seeders\ConferencePhaseSeeder;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seed(ConferencePhaseSeeder::class);
});

function phaseSeededTrack(): Track
{
    return Track::where('name', ConferencePhaseSeeder::TRACK_NAME)
        ->whereHas('conference', fn ($query) => $query->where('title', ConferencePhaseSeeder::CONFERENCE_TITLE))
        ->firstOrFail();
}

test('it leaves one track in the bidding phase right now', function () {
    expect(Conference::where('title', ConferencePhaseSeeder::CONFERENCE_TITLE)->count())->toBe(1)
        ->and(Track::count())->toBe(1);

    $track = phaseSeededTrack();

    // getRawOriginal() because the Track display accessors return formatted
    // strings, which cannot be compared (SD-03).
    expect(strtotime($track->getRawOriginal('submission_close_at')))->toBeLessThan(now()->getTimestamp())
        ->and(strtotime($track->getRawOriginal('review_open_at')))->toBeGreaterThan(now()->getTimestamp());
});

test('a committee member can bid on a seeded paper — the derived window really is open', function () {
    $committee = User::where('email', 'committee1@test.com')->firstOrFail();

    $submission = Submission::where('track_id', phaseSeededTrack()->id)->firstOrFail();

    $this->actingAs($committee)
        ->post(route('submissions.bid', $submission))
        ->assertSessionHasNoErrors();

    expect($submission->bids()->count())->toBe(1);
});

test('it seeds accounts that are already verified', function () {
    // The whole app sits behind the `verified` middleware, so an unverified
    // demo account could not even reach the bidding page asserted below.
    expect(User::whereNull('email_verified_at')->exists())->toBeFalse();

    $committee = User::where('email', 'committee1@test.com')->firstOrFail();

    $this->actingAs($committee)
        ->get(route('bidding.index'))
        ->assertOk();
});

test('it seeds papers and a committee, so the phase has something to show', function () {
    $track = phaseSeededTrack();

    expect(Submission::where('track_id', $track->id)->where('status', SubmissionStatus::Submitted->value)->count())->toBe(3);

    $roles = DB::table('model_has_roles')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('model_has_roles.team_id', $track->conference_id)
        ->pluck('roles.name');

    expect($roles->sort()->values()->toArray())
        ->toBe(['chair', 'committee_member', 'committee_member', 'committee_member']);
});

test('it keeps the phase ordering chain monotone so the conference stays editable', function () {
    $track = phaseSeededTrack();

    $chain = collect([
        'submission_open_at',
        'submission_close_at',
        'review_open_at',
        'review_close_at',
        'decision_open_at',
        'decision_close_at',
    ])->map(fn (string $column) => strtotime($track->getRawOriginal($column)));

    expect($chain->values()->toArray())->toBe($chain->sort()->values()->toArray());
});

test('running it twice changes nothing', function () {
    $before = [
        Conference::count(),
        Track::count(),
        Submission::count(),
        User::count(),
        phaseSeededTrack()->getRawOriginal('submission_close_at'),
    ];

    $this->seed(ConferencePhaseSeeder::class);

    expect([
        Conference::count(),
        Track::count(),
        Submission::count(),
        User::count(),
        phaseSeededTrack()->getRawOriginal('submission_close_at'),
    ])->toBe($before);
});
