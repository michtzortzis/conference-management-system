<?php

use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('SubmissionPolicy', function () {
    test('author can view own submission', function () {
        $author = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $author->assignRole('author');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'user_id' => $author->id,
            'title' => 'Test Paper',
            'abstract' => 'Test abstract',
            'status' => 'submitted',
        ]);

        $response = $this->actingAs($author)
            ->get(route('submissions.show', $submission));

        $response->assertStatus(200);
    });

    test('non-author cannot view another author submission', function () {
        $author = User::factory()->create();
        $otherUser = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'user_id' => $author->id,
            'title' => 'Test Paper',
            'abstract' => 'Test abstract',
            'status' => 'submitted',
        ]);

        $response = $this->actingAs($otherUser)
            ->get(route('submissions.show', $submission));

        $response->assertStatus(403);
    });

    test('chair can view any submission in their conference', function () {
        $chair = User::factory()->create();
        $author = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $chair->assignRole('chair');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'user_id' => $author->id,
            'title' => 'Test Paper',
            'abstract' => 'Test abstract',
            'status' => 'submitted',
        ]);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'user_id' => $author->id,
            'title' => 'Test Paper',
            'abstract' => 'Test abstract',
            'status' => 'submitted',
        ]);

        $response = $this->actingAs($chair)
            ->get(route('submissions.show', $submission));

        $response->assertStatus(200);
    });

    test('author with submission:submit permission can create submission', function () {
        $user = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        $track = Track::create([
            'conference_id' => $conference->id,
            'name' => 'Test Track',
            'description' => 'Test description',
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $user->assignRole('author');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $response = $this->actingAs($user)
            ->post(route('submissions.store'), [
                'conference_id' => $conference->id,
                'track_id' => $track->id,
                'title' => 'Test Paper',
                'abstract' => 'Test abstract',
            ]);

        $response->assertRedirect();
        expect(Submission::where('title', 'Test Paper')->exists())->toBeTrue();
    });

    test('author can update own submission in draft status', function () {
        $author = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $author->assignRole('author');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'user_id' => $author->id,
            'title' => 'Test Paper',
            'abstract' => 'Test abstract',
            'status' => 'draft',
        ]);

        $response = $this->actingAs($author)
            ->put(route('submissions.update', $submission), [
                'title' => 'Updated Title',
                'abstract' => 'Updated abstract',
            ]);

        $response->assertRedirect();
        expect($submission->fresh()->title)->toBe('Updated Title');
    });

    test('author can update submitted submission and it resets to draft', function () {
        $author = User::factory()->create();

        $conference = Conference::create([
            'title' => 'Test Conference',
            'description' => 'Test description',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
        ]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $author->assignRole('author');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $submission = Submission::create([
            'conference_id' => $conference->id,
            'user_id' => $author->id,
            'title' => 'Test Paper',
            'abstract' => 'Test abstract',
            'status' => 'submitted',
        ]);

        $response = $this->actingAs($author)
            ->put(route('submissions.update', $submission), [
                'title' => 'Updated Title',
                'abstract' => 'Updated abstract',
            ]);

        $response->assertRedirect();
        expect($submission->fresh()->title)->toBe('Updated Title');
        expect($submission->fresh()->status)->toBe(SubmissionStatus::Draft);
    });
});
