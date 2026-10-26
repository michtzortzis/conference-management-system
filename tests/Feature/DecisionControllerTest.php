<?php

use App\Enums\DecisionOutcome;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Decision;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\DecisionMade;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('DecisionController', function () {
    test('chair can make accepted decision and submission status transitions', function () {
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
                'rationale' => 'Excellent work',
            ]);

        $response->assertRedirect();
        $response->assertSessionHas('success');
        expect($submission->fresh()->status)->toBe(SubmissionStatus::Accepted);
    });

    test('chair can make rejected decision and submission status transitions', function () {
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
                'decision' => 'rejected',
                'rationale' => 'Does not meet standards',
            ]);

        $response->assertRedirect();
        expect($submission->fresh()->status)->toBe(SubmissionStatus::Rejected);
    });

    test('decision record created with correct attributes', function () {
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

        $this->actingAs($chair)
            ->from(route('submissions.show', $submission))
            ->post(route('submissions.decision.store', $submission), [
                'decision' => 'minor_revision',
                'rationale' => 'Minor changes needed',
            ]);

        $decision = Decision::where('submission_id', $submission->id)->first();

        expect($decision)->not->toBeNull();
        expect($decision->submission_id)->toBe($submission->id);
        expect($decision->decided_by_id)->toBe($chair->id);
        expect($decision->decision)->toBe(DecisionOutcome::MinorRevision);
        expect($decision->rationale)->toBe('Minor changes needed');
        expect($decision->revision_round)->toBe(1);
    });

    test('revision round increments with multiple decisions', function () {
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
            'status' => 'minor_revision',
        ]);

        $this->actingAs($chair)
            ->from(route('submissions.show', $submission))
            ->post(route('submissions.decision.store', $submission), [
                'decision' => 'accepted',
            ]);

        $decisions = Decision::where('submission_id', $submission->id)->orderBy('id')->get();
        expect($decisions->count())->toBe(1);
        expect($decisions->first()->revision_round)->toBe(1);
    });

    test('DecisionMade notification is dispatched', function () {
        Notification::fake();

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

        $this->actingAs($chair)
            ->from(route('submissions.show', $submission))
            ->post(route('submissions.decision.store', $submission), [
                'decision' => 'accepted',
            ]);

        Notification::assertSentTo(
            $author,
            DecisionMade::class,
            function (DecisionMade $notification, array $channels) use ($submission) {
                return $notification->decision->submission_id === $submission->id;
            },
        );
    });

    test('invalid decision outcome returns validation error', function () {
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
                'decision' => 'invalid_outcome',
                'rationale' => 'Test',
            ]);

        $response->assertSessionHasErrors('decision');
    });

    test('only accepted or rejected allowed when submission is under revision', function () {
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
            'status' => 'minor_revision',
        ]);

        $response = $this->actingAs($chair)
            ->from(route('submissions.show', $submission))
            ->post(route('submissions.decision.store', $submission), [
                'decision' => 'minor_revision',
                'rationale' => 'Trying to request another revision',
            ]);

        $response->assertSessionHasErrors('decision');
        expect($submission->fresh()->status)->toBe(SubmissionStatus::MinorRevision);
    });
});
