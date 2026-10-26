<?php

use App\Models\Conference;
use App\Models\Submission;
use App\Models\SubmissionFile;
use App\Models\Track;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * A track whose review window is open right now (CFP already closed, decision ahead).
 *
 * Fixtures that staff or review a submission need one. Since AD-012/SD-05 the write
 * paths derive legality from `App\Support\PhaseGuards::reviewable()` instead of trusting
 * the UI, and a submission with no track has no boundary pair to derive from — so it is
 * refused rather than silently allowed, which is what made the oldest fixtures fail.
 *
 * @see docs/03-decisions (AD-012), specs/mermaid-figure-reverification-report.md D-3.3
 */
function openReviewTrack(Conference $conference, string $name = 'Main Track', array $boundaries = []): Track
{
    return Track::create(array_merge([
        'conference_id' => $conference->id,
        'name' => $name,
        'submission_open_at' => now()->subDays(10),
        'submission_close_at' => now()->subDays(5),
        'review_open_at' => now()->subDays(4),
        'review_close_at' => now()->addDays(7),
        'decision_open_at' => now()->addDays(8),
        'decision_close_at' => now()->addDays(15),
    ], $boundaries));
}

/**
 * Attach a real file row to a submission: `SubmissionPolicy::submit` (D-7.1) enforces the
 * `⟨CFP open, ≥1 file⟩` guard drawn in Fig 3.7, so a draft cannot legally be submitted
 * without one. Tests about something *other* than the file rule need this to get to the
 * state they claim to set up; `SubmissionFileTest` covers the rule itself.
 */
function attachFileToSubmission(Submission $submission, string $filename = 'paper.pdf'): SubmissionFile
{
    return $submission->files()->create([
        'filename' => $filename,
        'path' => 'submissions/'.$submission->id.'/'.$filename,
        'mime_type' => 'application/pdf',
        'size' => 1024,
    ]);
}
