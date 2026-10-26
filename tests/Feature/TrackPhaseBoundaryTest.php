<?php

use App\Enums\Phase;
use App\Models\Track;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reads the phase boundaries as dates rather than display strings', function () {
    $track = new Track(['submission_close_at' => '2026-09-10 00:00:00']);

    expect($track->submission_close_at)->toBeInstanceOf(Carbon::class)
        ->and($track->submission_close_at->toDateString())->toBe('2026-09-10');
});

it('opens a phase at the start of its day and closes it at the end of its day', function () {
    $track = new Track([
        'submission_open_at' => '2026-09-01 13:45:00',
        'submission_close_at' => '2026-09-10 07:15:00',
    ]);

    expect($track->phaseOpensAt(Phase::Submission)->toDateTimeString())->toBe('2026-09-01 00:00:00')
        ->and($track->phaseClosesAt(Phase::Submission)->toDateTimeString())->toBe('2026-09-10 23:59:59');
});

it('treats a missing boundary as unbounded', function () {
    $track = new Track(['review_open_at' => null, 'decision_close_at' => null]);

    expect($track->phaseOpensAt(Phase::Review))->toBeNull()
        ->and($track->phaseClosesAt(Phase::Decision))->toBeNull();
});

it('leaves the stored boundary untouched while resolving a phase instant', function () {
    $track = new Track(['review_close_at' => '2026-10-05 08:30:00']);

    $track->phaseClosesAt(Phase::Review);

    expect($track->review_close_at->toDateTimeString())->toBe('2026-10-05 08:30:00')
        ->and($track->phaseClosesAt(Phase::Review)->toDateTimeString())->toBe('2026-10-05 23:59:59');
});

it('maps each phase onto its own pair of boundary columns', function () {
    expect(Phase::Submission->openColumn())->toBe('submission_open_at')
        ->and(Phase::Review->closeColumn())->toBe('review_close_at')
        ->and(Phase::Decision->openColumn())->toBe('decision_open_at')
        ->and(Phase::Decision->closeColumn())->toBe('decision_close_at');
});
