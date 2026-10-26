<?php

use App\Models\Conference;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('ConferencePolicy', function () {
    test('any authenticated user can view conferences list', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('conferences.index'));

        $response->assertStatus(200);
    });

    test('user without permission cannot create conference', function () {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('conferences.store'), [
                'title' => 'Test Conference',
                'description' => 'Test description',
                'tracks' => [
                    [
                        'name' => 'Track 1',
                        'description' => 'Description',
                        'submission_open_at' => now()->toDateString(),
                        'submission_close_at' => now()->addDays(30)->toDateString(),
                    ],
                ],
            ]);

        $response->assertStatus(403);
    });

    test('user with organizer role (conference:create permission) can create conference', function () {
        $user = User::factory()->create();
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $user->assignRole('organizer');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $response = $this->actingAs($user)
            ->post(route('conferences.store'), [
                'title' => 'Test Conference',
                'description' => 'Test description',
                'tracks' => [
                    [
                        'name' => 'Track 1',
                        'description' => 'Description',
                        'submission_open_at' => now()->toDateString(),
                        'submission_close_at' => now()->addDays(30)->toDateString(),
                    ],
                ],
            ]);

        $response->assertRedirect(route('conferences.index'));
        expect(Conference::where('title', 'Test Conference')->exists())->toBeTrue();
    });

    test('user without conference:manage cannot update conference', function () {
        $user = User::factory()->create();
        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        $response = $this->actingAs($user)
            ->put(route('conferences.update', $conference), [
                'title' => 'Updated Title',
                'description' => 'Updated description',
                'tracks' => [
                    [
                        'name' => 'Track 1',
                        'description' => 'Description',
                        'submission_open_at' => now()->toDateString(),
                        'submission_close_at' => now()->addDays(30)->toDateString(),
                    ],
                ],
            ]);

        $response->assertStatus(403);
    });

    test('user with conference:manage can update conference', function () {
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
                'title' => 'Updated Title',
                'description' => 'Updated description',
                'tracks' => [
                    [
                        'name' => 'Track 1',
                        'description' => 'Description',
                        'submission_open_at' => now()->toDateString(),
                        'submission_close_at' => now()->addDays(30)->toDateString(),
                    ],
                ],
            ]);

        $response->assertRedirect(route('conferences.index'));
        expect($conference->fresh()->title)->toBe('Updated Title');
    });

    test('user without conference:manage cannot delete conference', function () {
        $user = User::factory()->create();
        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        $response = $this->actingAs($user)
            ->delete(route('conferences.destroy', $conference));

        $response->assertStatus(403);
        expect($conference->fresh())->not->toBeNull();
    });

    test('user with conference:archive can delete conference', function () {
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
            ->delete(route('conferences.destroy', $conference));

        $response->assertRedirect(route('conferences.index'));
        expect($conference->fresh()->trashed())->toBeTrue();
    });
});
