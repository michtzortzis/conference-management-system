<?php

namespace App\Support;

use App\Enums\DecisionOutcome;
use App\Enums\Phase;
use App\Models\Submission;
use App\Models\Track;
use Carbon\CarbonInterface;

/**
 * The single review-legality predicate (AD-012, SD-05, re-verification D-3.3 / D-8.2 / D-8.3).
 *
 * ReviewController used to compute `review_window_open` as a render prop and enforce
 * nothing, and the two assignment paths carried their own bare `review_close_at`
 * comparisons. That is the IN-15 / D12 shape this class exists to end: **one predicate,
 * every write path, plus the two assign paths** — because a policy cannot own it (the
 * conference-independent `submission:review` role passes `ReviewPolicy::create` before any
 * window logic, so a `reviewTarget` policy would be a bypassable second gate).
 *
 * Two windows make reviewer-facing work legal:
 *  1. the review window itself; or
 *  2. an OPEN REVISION ROUND while the decision window is open.
 *
 * (2) is why this is a shared predicate and not `phaseClosesAt(Phase::Review)` inline:
 * the six-boundary ordering of AD-005 forces `review_close_at <= decision_open_at`, so a
 * round-2 review or assignment gated on the review window is deadlocked by construction —
 * it would be permanently illegal exactly when it is the only work left to do.
 */
class PhaseGuards
{
    /**
     * Outcomes that keep a revision round open (rounds live on decisions.revision_round, R4).
     */
    private const REVISION_OUTCOMES = [DecisionOutcome::MinorRevision, DecisionOutcome::MajorRevision];

    /**
     * Can reviewer-facing work run on this submission right now?
     *
     * Round 1 refuses **before** the review window opens, in every mode. Outside the
     * review window the answer comes from the revision round, never from a free
     * `force` flag: an absent track means no window can be derived, so it refuses.
     */
    public static function reviewable(Submission $submission, ?CarbonInterface $now = null): bool
    {
        $now ??= now();
        $track = $submission->track;

        if (! $track instanceof Track) {
            return false;
        }

        $reviewOpensAt = $track->phaseOpensAt(Phase::Review);
        $reviewClosesAt = $track->phaseClosesAt(Phase::Review);

        // phaseOpensAt/phaseClosesAt already normalise to start/end of day (Track:56-72).
        if ($reviewOpensAt !== null && $now->lt($reviewOpensAt)) {
            return false;
        }

        if ($reviewClosesAt === null || $now->lte($reviewClosesAt)) {
            return true;
        }

        return static::openRevisionRound($submission, $now);
    }

    /**
     * True when the newest decision asks for revisions and the decision window is open.
     */
    public static function openRevisionRound(Submission $submission, ?CarbonInterface $now = null): bool
    {
        $now ??= now();
        $track = $submission->track;

        if (! $track instanceof Track) {
            return false;
        }

        $opensAt = $track->phaseOpensAt(Phase::Decision);
        $closesAt = $track->phaseClosesAt(Phase::Decision);

        if ($opensAt === null || $closesAt === null || $now->lt($opensAt) || $now->gt($closesAt)) {
            return false;
        }

        $latest = $submission->decision()->latest('id')->first();

        return $latest !== null
            && $latest->decision instanceof DecisionOutcome
            && in_array($latest->decision, self::REVISION_OUTCOMES, true);
    }

    /**
     * One message shape for every refusal, so UI and tests assert the same sentence.
     */
    public static function message(Submission $submission): string
    {
        $closesAt = $submission->track?->phaseClosesAt(Phase::Review);

        return $closesAt === null
            ? 'The review period for this submission is not open.'
            : "The review period for this submission closed on {$closesAt->toDateString()} and no revision round is open.";
    }
}
