<?php

namespace Database\Seeders;

use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\Submission;
use App\Models\Track;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Back-dated demo data that leaves a conference already inside the bidding
 * phase, so demos and UAT can reach bidding, reviewer assignment and decisions
 * without the chair-only phase override (SD-02) and without editing the
 * database by hand. Opt-in: see DatabaseSeeder and config/conference.php.
 */
class ConferencePhaseSeeder extends Seeder
{
    public const CONFERENCE_TITLE = 'Phase Demo Conference';

    public const TRACK_NAME = 'Bidding Phase Track';

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Roles are a hard prerequisite for the grants below. Calling this
        // seeder again is harmless (it is firstOrCreate-based) and lets a
        // standalone `db:seed --class=ConferencePhaseSeeder` work on a fresh
        // database.
        $this->call(RolesAndPermissionsSeeder::class);

        $roles = new TestUserSeeder;

        $conference = Conference::firstOrCreate(
            ['title' => self::CONFERENCE_TITLE],
            [
                'description' => 'Demo conference whose call for papers has already closed, so the derived bidding window is open right now.',
                'start_date' => now()->addDays(90)->toDateString(),
                'end_date' => now()->addDays(93)->toDateString(),
            ]
        );

        $track = $conference->tracks()->updateOrCreate(
            ['name' => self::TRACK_NAME],
            $this->biddingPhaseBoundaries()
        );

        $this->seedCommittee($conference, $roles);
        $this->seedSubmissions($conference, $track, $roles);
    }

    /**
     * Boundaries that put the track in the bidding phase at the moment the
     * seeder runs.
     *
     * Bidding is a derived window — it opens when the CFP closes and shuts
     * again when review opens (BidController::biddingWindowOpen) — so the CFP
     * closed yesterday and review starts in a fortnight. The later boundaries
     * keep the ordering chain monotone (submission_close_at <= review_open_at
     * <= review_close_at <= decision_open_at <= decision_close_at) so the
     * conference stays editable after seeding.
     *
     * @return array<string, mixed>
     */
    private function biddingPhaseBoundaries(): array
    {
        return [
            'description' => 'Papers submitted while the CFP was open; now waiting for reviewers to bid.',
            'submission_open_at' => now()->subDays(30),
            'submission_close_at' => now()->subDay(),
            'review_open_at' => now()->addDays(14),
            'review_close_at' => now()->addDays(21),
            'decision_open_at' => now()->addDays(22),
            'decision_close_at' => now()->addDays(28),
        ];
    }

    /**
     * A chair and three committee members for this conference, reusing the
     * demo accounts TestUserSeeder provides when it has already run.
     */
    private function seedCommittee(Conference $conference, TestUserSeeder $roles): void
    {
        $chair = $this->user('chair1@test.com', 'Chair', 'One');
        $roles->assignConferenceRole($chair, 'chair', $conference->id);

        foreach (['one', 'two', 'three'] as $index => $word) {
            $committee = $this->user('committee'.($index + 1).'@test.com', 'Committee', ucfirst($word));
            $roles->assignConferenceRole($committee, 'committee_member', $conference->id);
        }
    }

    /**
     * Submitted papers for committee members to bid on — the bidding page has
     * nothing to show while the track is empty.
     */
    private function seedSubmissions(Conference $conference, Track $track, TestUserSeeder $roles): void
    {
        $papers = [
            'Attention Sparsity in Long-Context Transformers',
            'Federated Learning Under Skewed Clinical Data',
            'Graph Neural Networks for Traffic Forecasting',
        ];

        $authors = ['one', 'two', 'three'];

        foreach ($papers as $index => $title) {
            $author = $this->user('author'.($index + 1).'@test.com', 'Author', ucfirst($authors[$index]));
            $roles->assignGlobalRole($author, 'author');

            Submission::updateOrCreate(
                ['track_id' => $track->id, 'title' => $title],
                [
                    'conference_id' => $conference->id,
                    'user_id' => $author->id,
                    'abstract' => "Abstract for '{$title}'. Submitted before the call for papers closed; awaiting reviewer bids.",
                    'keywords' => ['demo', 'bidding'],
                    'status' => SubmissionStatus::Submitted,
                ]
            );
        }
    }

    /**
     * Reuse a demo account when it exists, otherwise create it.
     *
     * Accounts are verified here too, so the seeder stays usable standalone on
     * a database whose demo accounts predate verification: the `verified`
     * middleware gates every route in routes/web.php, and a bidder that cannot
     * open the bidding page defeats the point of back-dating the phase.
     */
    private function user(string $email, string $firstName, string $lastName): User
    {
        $user = User::firstOrCreate(
            ['email' => $email],
            [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'password' => Hash::make('password'),
            ]
        );

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
        }

        return $user;
    }
}
