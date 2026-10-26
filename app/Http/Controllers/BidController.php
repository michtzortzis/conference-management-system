<?php

namespace App\Http\Controllers;

use App\Enums\Phase;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\SubmissionStatus;
use App\Models\Conference;
use App\Models\ReviewAssignment;
use App\Models\ReviewerConflict;
use App\Models\Submission;
use App\Models\SubmissionBid;
use App\Models\User;
use App\Notifications\BidReceived;
use App\Services\ConflictOfInterestService;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\PermissionRegistrar;

class BidController extends Controller
{
    use AuthorizesRequests;

    /**
     * List the submissions a committee member can bid on, plus their own bids.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        $conferenceIds = $this->committeeConferences($user);

        // Biddable: submitted (non-draft) submissions in the user's
        // conferences whose derived bidding window is open, excluding the
        // user's own submissions and ones already assigned to them.
        $submissions = Submission::with(['conference', 'track', 'coAuthors'])
            ->whereIn('conference_id', $conferenceIds)
            ->where('status', '!=', SubmissionStatus::Draft)
            ->get()
            ->filter(function (Submission $submission) use ($user) {
                return $this->biddingWindowOpen($submission)
                    && $this->canBid($submission, $user);
            })
            ->values();

        $bidSubmissionIds = SubmissionBid::where('user_id', $user->id)
            ->whereIn('submission_id', $submissions->pluck('id'))
            ->pluck('submission_id')
            ->all();

        // COI flags per biddable submission, so the bidder can see their own
        // conflict state. In double-blind mode the auto reason is withheld from
        // the bidder (it is derived from author identity).
        $coi = app(ConflictOfInterestService::class);
        $coiFlags = [];
        foreach ($submissions as $submission) {
            $auto = $coi->analyze($submission, $user);
            $conflict = ReviewerConflict::where('submission_id', $submission->id)
                ->where('reviewer_id', $user->id)
                ->first();

            $coiFlags[$submission->id] = [
                'declared' => $coi->hasDeclared($submission, $user),
                'auto_level' => $auto->level,
                'auto_reason' => $auto->reason,
                'overridable' => $auto->overridable,
                'overridden' => $conflict?->overridden ?? false,
            ];
        }

        $doubleBlindConferenceIds = Conference::whereIn('id', $conferenceIds)
            ->where('double_blind_review', true)
            ->pluck('id')
            ->all();

        // In double-blind mode the bid list is bidder-facing: never expose the
        // submitter or any co-author identity, and never leak the COI reason.
        foreach ($submissions as $submission) {
            if (in_array($submission->conference_id, $doubleBlindConferenceIds)) {
                $submission->setAttribute('user_id', null);
                $submission->setRelation('user', null);
                $submission->setRelation('coAuthors', collect());
                if (isset($coiFlags[$submission->id])) {
                    $coiFlags[$submission->id]['auto_reason'] = null;
                }
            }
        }

        return Inertia::render('Bidding/Index', [
            'submissions' => $submissions,
            'bid_submission_ids' => $bidSubmissionIds,
            'coi_flags' => $coiFlags,
            'max_reviewers' => (int) config('conference.max_reviewers', 5),
        ]);
    }

    /**
     * Record a bid for a submission.
     */
    public function store(Request $request, Submission $submission)
    {
        $user = $request->user();

        if (! $this->isCommitteeMember($user, $submission)) {
            return back()->withErrors(['submission' => 'Only committee members can bid on submissions.']);
        }

        if (! $this->biddingWindowOpen($submission)) {
            return back()->withErrors(['submission' => 'Bidding is closed for this submission.']);
        }

        if ($submission->status === SubmissionStatus::Draft) {
            return back()->withErrors(['submission' => 'Cannot bid on a draft submission.']);
        }

        if ($this->isOwnSubmission($submission, $user)) {
            return back()->withErrors(['submission' => 'You cannot bid on your own submission.']);
        }

        if ($this->isAssigned($submission, $user)) {
            return back()->withErrors(['submission' => 'You are already assigned to review this submission.']);
        }

        // COI gate: a declared conflict always blocks; an auto-detected hard
        // conflict blocks unless the chair has already overridden it.
        $coi = app(ConflictOfInterestService::class);

        if ($coi->hasDeclared($submission, $user)) {
            return back()->withErrors(['submission' => 'You have a declared conflict of interest with this submission and cannot bid on it.']);
        }

        $coiResult = $coi->analyze($submission, $user);
        if ($coiResult->isHard() && ! $coiResult->overridable) {
            return back()->withErrors(['submission' => 'You are an author of this submission and cannot review it.']);
        }

        $overridden = ReviewerConflict::where('submission_id', $submission->id)
            ->where('reviewer_id', $user->id)
            ->whereIn('level', ['hard', 'soft'])
            ->where('overridden', true)
            ->exists();

        if ($coiResult->isHard() && ! $overridden) {
            return back()->withErrors(['submission' => 'You have a potential conflict of interest with this submission and cannot bid on it.']);
        }

        $maxReviewers = (int) config('conference.max_reviewers', 5);
        $activeBids = SubmissionBid::where('user_id', $user->id)->count();
        if ($activeBids >= $maxReviewers) {
            return back()->withErrors([
                'submission' => "You can bid on at most {$maxReviewers} submissions.",
            ]);
        }

        SubmissionBid::updateOrCreate(
            ['submission_id' => $submission->id, 'user_id' => $user->id],
            []
        );

        $user->notify(new BidReceived($submission));

        return back()->with('success', 'Bid recorded.');
    }

    /**
     * Withdraw a bid for a submission.
     */
    public function destroy(Request $request, Submission $submission)
    {
        $user = $request->user();

        // D-4.4: withdrawing a bid is a write against a phase-owned resource, so it
        // re-derives the same legality `store()` does instead of trusting the UI, which
        // only hides the button. Before this, any verified committee member could
        // withdraw a bid after the derived bidding window had closed.
        if (! $this->isCommitteeMember($user, $submission)) {
            return back()->withErrors(['submission' => 'Only committee members can manage bids.']);
        }

        if (! $this->biddingWindowOpen($submission)) {
            return back()->withErrors(['submission' => 'Bidding is closed for this submission — its bids can no longer be changed.']);
        }

        $deleted = SubmissionBid::where('submission_id', $submission->id)
            ->where('user_id', $user->id)
            ->delete();

        if ($deleted === 0) {
            return back()->withErrors(['submission' => 'You have no bid on this submission.']);
        }

        // Deliberately NOT re-running the COI screen here (unlike store()): a declared
        // conflict is a reason to refuse *entry* to the review pool, and refusing to
        // remove a conflicted bidder would be the wrong direction of protection.
        return back()->with('success', 'Bid withdrawn.');
    }

    /**
     * Whether the submission's derived bidding window is open:
     * the CFP has closed AND reviews have not started yet.
     */
    private function biddingWindowOpen(Submission $submission): bool
    {
        $track = $submission->track;
        if (! $track) {
            return false;
        }

        $now = now();

        // Bidding shares its opening boundary with the CFP's closing one, and the
        // two gate in opposite directions: the CFP stays open through the whole
        // day the track closes, so bidding only starts once that day is over.
        $cfpClosesAt = $track->phaseClosesAt(Phase::Submission);
        if (! $cfpClosesAt || $now <= $cfpClosesAt) {
            return false;
        }

        $reviewOpensAt = $track->phaseOpensAt(Phase::Review);
        if ($reviewOpensAt && $now >= $reviewOpensAt) {
            return false;
        }

        return true;
    }

    /**
     * Whether the user may bid on this submission: not the submitter, not a
     * registered co-author, and not already assigned to review it.
     */
    private function canBid(Submission $submission, User $user): bool
    {
        return ! $this->isOwnSubmission($submission, $user)
            && ! $this->isAssigned($submission, $user);
    }

    private function isOwnSubmission(Submission $submission, User $user): bool
    {
        return $submission->user_id === $user->id
            || $submission->coAuthors()->where('user_id', $user->id)->exists();
    }

    private function isAssigned(Submission $submission, User $user): bool
    {
        return ReviewAssignment::where('submission_id', $submission->id)
            ->where('reviewer_id', $user->id)
            ->whereIn('status', [ReviewAssignmentStatus::Pending, ReviewAssignmentStatus::Accepted])
            ->exists();
    }

    /**
     * Conferences where the user holds the committee_member role.
     */
    private function committeeConferences(User $user): array
    {
        $ids = [];
        foreach (Conference::all() as $conference) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
            // Re-query roles per conference: `hasRole()` caches the roles
            // relation after the first iteration, so later conferences would
            // otherwise be missed for members scoped to a later conference.
            $roles = $user->roles()->pluck('name')->toArray();
            if (in_array('committee_member', $roles, true)) {
                $ids[] = $conference->id;
            }
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
        $user->unsetRelation('roles');

        return $ids;
    }

    private function isCommitteeMember(User $user, Submission $submission): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($submission->conference_id);
        $isMember = $user->hasRole('committee_member');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $user->unsetRelation('roles');

        return $isMember;
    }
}
