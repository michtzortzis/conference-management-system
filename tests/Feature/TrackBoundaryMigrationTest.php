<?php

use App\Enums\Phase;
use App\Models\Conference;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Path of the migration that moves the six track boundaries from MySQL
 * `timestamp` to `datetime`, relative to the base path as migrate's --path
 * option expects.
 */
function boundaryMigrationPath(): string
{
    $absolute = glob(database_path('migrations/*_change_track_phase_boundaries_to_datetime.php'))[0];

    return 'database/migrations/'.basename($absolute);
}

function boundaryTrack(): Track
{
    $conference = Conference::create(['title' => 'Boundary Migration Conference']);

    return Track::create([
        'conference_id' => $conference->id,
        'name' => 'Main Track',
        'submission_open_at' => '2026-06-01 00:00:00',
        'submission_close_at' => '2026-07-20 00:00:00',
        'review_open_at' => '2026-07-25 09:00:00',
        'review_close_at' => '2026-08-30 17:30:00',
        'decision_open_at' => '2026-09-02 08:00:00',
        'decision_close_at' => null,
    ]);
}

it('keeps every stored boundary identical when the columns change type', function () {
    $track = boundaryTrack();

    $before = DB::table('tracks')->where('id', $track->id)->first();

    Artisan::call('migrate:rollback', ['--path' => boundaryMigrationPath()]);
    Artisan::call('migrate', ['--path' => boundaryMigrationPath()]);

    $after = DB::table('tracks')->where('id', $track->id)->first();

    expect($after)->toEqual($before)
        ->and($after->submission_close_at)->toBe('2026-07-20 00:00:00')
        ->and($after->review_close_at)->toBe('2026-08-30 17:30:00')
        ->and($after->decision_close_at)->toBeNull();
});

it('still reads the rebuilt boundaries through the phase convention', function () {
    $track = boundaryTrack();

    Artisan::call('migrate:rollback', ['--path' => boundaryMigrationPath()]);
    Artisan::call('migrate', ['--path' => boundaryMigrationPath()]);

    $rebuilt = $track->fresh();

    expect($rebuilt->phaseOpensAt(Phase::Review)->toDateTimeString())->toBe('2026-07-25 00:00:00')
        ->and($rebuilt->phaseClosesAt(Phase::Review)->toDateTimeString())->toBe('2026-08-30 23:59:59')
        ->and($rebuilt->phaseClosesAt(Phase::Decision))->toBeNull();
});

it('keeps the boundary columns nullable through the change', function () {
    Artisan::call('migrate:rollback', ['--path' => boundaryMigrationPath()]);
    Artisan::call('migrate', ['--path' => boundaryMigrationPath()]);

    // change() re-specifies the whole column, so a forgotten nullable() would
    // surface here rather than on the next insert.
    $track = boundaryTrack();
    $track->update(['decision_open_at' => null, 'review_close_at' => null]);

    expect($track->fresh()->decision_open_at)->toBeNull()
        ->and($track->fresh()->review_close_at)->toBeNull();
});
