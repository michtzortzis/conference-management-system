<?php

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

describe('DecisionPolicy', function () {
    test('chair can store decision for their conference', function () {
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
            'status' => 'under_review',
        ]);

        $response = $this->actingAs($chair)
            ->from(route('submissions.show', $submission))
            ->post(route('submissions.decision.store', $submission), [
                'decision' => 'accepted',
                'rationale' => 'Great paper',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
    });

    test('non-chair cannot store decision', function () {
        $user = User::factory()->create();
        $author = User::factory()->create();

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
            'status' => 'under_review',
        ]);

        $response = $this->actingAs($user)
            ->from(route('submissions.show', $submission))
            ->post(route('submissions.decision.store', $submission), [
                'decision' => 'accepted',
                'rationale' => 'Great paper',
            ]);

        $response->assertStatus(403);
    });

    test('author cannot store decision for their own submission', function () {
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
            'status' => 'under_review',
        ]);

        $response = $this->actingAs($author)
            ->from(route('submissions.show', $submission))
            ->post(route('submissions.decision.store', $submission), [
                'decision' => 'accepted',
                'rationale' => 'Great paper',
            ]);

        $response->assertStatus(403);
    });
});
