<?php

use App\Models\Conference;
use App\Models\Track;
use App\Models\User;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('Conference update tracks', function () {
    test('update preserves existing track ids and updates their data', function () {
        $user = User::factory()->create();
        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);
        $originalTrack = $conference->tracks()->create([
            'name' => 'Original Track',
            'description' => 'Original description',
            'submission_open_at' => now()->toDateString(),
            'submission_close_at' => now()->addDays(30)->toDateString(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $user->assignRole('chair');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $response = $this->actingAs($user)
            ->put(route('conferences.update', $conference), [
                'title' => 'Updated Conference',
                'description' => 'Updated description',
                'tracks' => [
                    [
                        'id' => $originalTrack->id,
                        'name' => 'Updated Track',
                        'description' => 'Updated description',
                        'submission_open_at' => now()->toDateString(),
                        'submission_close_at' => now()->addDays(30)->toDateString(),
                    ],
                ],
            ]);

        $response->assertRedirect(route('conferences.index'));

        $updatedTrack = Track::find($originalTrack->id);
        expect($updatedTrack)->not->toBeNull();
        expect($updatedTrack->name)->toBe('Updated Track');
        expect($updatedTrack->description)->toBe('Updated description');
        expect(Track::count())->toBe(1);
    });

    test('update creates new tracks when no id is provided', function () {
        $user = User::factory()->create();
        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $user->assignRole('chair');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $response = $this->actingAs($user)
            ->put(route('conferences.update', $conference), [
                'title' => 'Updated Conference',
                'description' => 'Updated description',
                'tracks' => [
                    [
                        'name' => 'New Track',
                        'description' => 'New description',
                        'submission_open_at' => now()->toDateString(),
                        'submission_close_at' => now()->addDays(30)->toDateString(),
                    ],
                ],
            ]);

        $response->assertRedirect(route('conferences.index'));
        expect(Track::where('conference_id', $conference->id)->count())->toBe(1);
    });

    test('update soft-deletes tracks not included in request', function () {
        $user = User::factory()->create();
        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);
        $track1 = $conference->tracks()->create([
            'name' => 'Track 1',
            'description' => 'Description 1',
            'submission_open_at' => now()->toDateString(),
            'submission_close_at' => now()->addDays(30)->toDateString(),
        ]);
        $track2 = $conference->tracks()->create([
            'name' => 'Track 2',
            'description' => 'Description 2',
            'submission_open_at' => now()->toDateString(),
            'submission_close_at' => now()->addDays(30)->toDateString(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $user->assignRole('chair');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $response = $this->actingAs($user)
            ->put(route('conferences.update', $conference), [
                'title' => 'Updated Conference',
                'description' => 'Updated description',
                'tracks' => [
                    [
                        'id' => $track1->id,
                        'name' => 'Updated Track 1',
                        'description' => 'Updated description 1',
                        'submission_open_at' => now()->toDateString(),
                        'submission_close_at' => now()->addDays(30)->toDateString(),
                    ],
                ],
            ]);

        $response->assertRedirect(route('conferences.index'));

        $updatedTrack1 = Track::find($track1->id);
        expect($updatedTrack1)->not->toBeNull();
        expect($updatedTrack1->name)->toBe('Updated Track 1');

        $deletedTrack2 = Track::withTrashed()->find($track2->id);
        expect($deletedTrack2)->not->toBeNull();
        expect($deletedTrack2->trashed())->toBeTrue();

        expect(Track::count())->toBe(1);
    });

    test('update can mix existing and new tracks', function () {
        $user = User::factory()->create();
        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);
        $existingTrack = $conference->tracks()->create([
            'name' => 'Existing Track',
            'description' => 'Existing description',
            'submission_open_at' => now()->toDateString(),
            'submission_close_at' => now()->addDays(30)->toDateString(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $user->assignRole('chair');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $response = $this->actingAs($user)
            ->put(route('conferences.update', $conference), [
                'title' => 'Updated Conference',
                'description' => 'Updated description',
                'tracks' => [
                    [
                        'id' => $existingTrack->id,
                        'name' => 'Updated Existing Track',
                        'description' => 'Updated description',
                        'submission_open_at' => now()->toDateString(),
                        'submission_close_at' => now()->addDays(30)->toDateString(),
                    ],
                    [
                        'name' => 'New Track',
                        'description' => 'New description',
                        'submission_open_at' => now()->toDateString(),
                        'submission_close_at' => now()->addDays(30)->toDateString(),
                    ],
                ],
            ]);

        $response->assertRedirect(route('conferences.index'));

        $updatedExisting = Track::find($existingTrack->id);
        expect($updatedExisting)->not->toBeNull();
        expect($updatedExisting->name)->toBe('Updated Existing Track');

        expect(Track::where('conference_id', $conference->id)->count())->toBe(2);
    });
});

describe('Conference date fallback', function () {
    test('clearing the conference dates keeps the stored values instead of writing a display string', function () {
        $user = User::factory()->create();
        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
        ]);
        $track = $conference->tracks()->create([
            'name' => 'Track 1',
            'submission_open_at' => now()->toDateString(),
            'submission_close_at' => now()->addDays(30)->toDateString(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $user->assignRole('chair');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        // The edit form sends '' when a date is cleared; ConvertEmptyStringsToNull
        // turns that into null and the controller falls back to the stored value.
        // While the models formatted those columns for display, the fallback wrote
        // "Mon Jun 01 2026" into a date column — rejected outright by MySQL's
        // strict mode, so this request was a 500 on dev.
        $response = $this->actingAs($user)
            ->put(route('conferences.update', $conference), [
                'title' => 'Updated Conference',
                'description' => 'Updated description',
                'start_date' => '',
                'end_date' => '',
                'tracks' => [
                    [
                        'id' => $track->id,
                        'name' => 'Track 1',
                        'submission_open_at' => now()->toDateString(),
                        'submission_close_at' => now()->addDays(30)->toDateString(),
                    ],
                ],
            ]);

        $response->assertSessionHasNoErrors();

        $fresh = $conference->fresh();
        $storedStartDate = (string) DB::table('conferences')->where('id', $conference->id)->value('start_date');
        $storedEndDate = (string) DB::table('conferences')->where('id', $conference->id)->value('end_date');

        expect($fresh->start_date)->toBeInstanceOf(Carbon::class)
            ->and($storedStartDate)->toMatch('/^2026-06-01( 00:00:00)?$/')
            ->and($storedEndDate)->toMatch('/^2026-06-05( 00:00:00)?$/');
    });

    test('the conference dates reach the frontend as ISO values rather than display text', function () {
        $user = User::factory()->create();
        $conference = Conference::create([
            'title' => 'Test Conference',
            'start_date' => '2026-06-01',
            'end_date' => '2026-06-05',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $user->assignRole('chair');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $serialized = $conference->fresh()->toArray();

        expect(Carbon::parse($serialized['start_date'])->toDateString())->toBe('2026-06-01')
            ->and(Carbon::parse($serialized['end_date'])->toDateString())->toBe('2026-06-05');
    });
});
