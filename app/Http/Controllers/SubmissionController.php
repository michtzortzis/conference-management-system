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
use App\Models\SubmissionCoAuthor;
use App\Models\User;
use App\Notifications\CoAuthorInvitation;
use App\Notifications\ReviewerAssigned;
use App\Notifications\SubmissionReceived;
use App\Services\ConflictOfInterestService;
use App\Support\PhaseGuards;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Inertia\Inertia;
use Spatie\Permission\PermissionRegistrar;

class SubmissionController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $globalRoles = $user->roles()->pluck('name')->toArray();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        if (in_array('author', $globalRoles) && ! in_array('chair', $globalRoles)) {
            $submissions = Submission::where('user_id', $user->id)
                ->orWhereHas('coAuthors', fn ($q) => $q->where('user_id', $user->id))
                ->with(['conference', 'track'])
                ->get();
        } else {
            $conferences = Conference::all();
            $conferenceIds = [];

            foreach ($conferences as $conference) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
                // Re-query roles per conference: `hasRole()` caches the roles
                // relation after the first iteration, so later conferences
                // would otherwise be missed for chairs scoped to a later one.
                $roles = $user->roles()->pluck('name')->toArray();
                if (in_array('chair', $roles, true)) {
                    $conferenceIds[] = $conference->id;
                }
                app(PermissionRegistrar::class)->setPermissionsTeamId(null);
            }

            $submissions = Submission::whereIn('conference_id', $conferenceIds)
                ->with(['conference', 'track'])
                ->get();
        }

        return Inertia::render('Submissions/Index', [
            'submissions' => $submissions,
        ]);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        $conferences = Conference::with(['fields', 'tracks', 'submissions'])->get();

        return Inertia::render('Submissions/Create', [
            'conferences' => $conferences,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'conference_id' => 'required|exists:conferences,id',
            'track_id' => 'required|exists:tracks,id',
            'title' => 'required|string|max:255',
            'abstract' => 'required|string',
            'keywords' => 'nullable|string|max:255',
            'co_authors' => 'nullable|array',
            'co_authors.*.id' => 'nullable|exists:submission_coauthors,id',
            'co_authors.*.first_name' => 'required|string|max:255',
            'co_authors.*.last_name' => 'required|string|max:255',
            'co_authors.*.email' => 'required|email|max:255',
        ]);

        $conference = Conference::with('tracks')->findOrFail($validated['conference_id']);
        $this->authorize('create', [Submission::class, $conference]);

        $track = $conference->tracks()->find($validated['track_id']);

        $now = now();
        $cfpOpensAt = $track->phaseOpensAt(Phase::Submission);
        if ($cfpOpensAt && $now < $cfpOpensAt) {
            return redirect()->back()
                ->withErrors(['track_id' => 'Submission period has not started yet.'])
                ->withInput();
        }

        $cfpClosesAt = $track->phaseClosesAt(Phase::Submission);
        if ($cfpClosesAt && $now > $cfpClosesAt) {
            return redirect()->back()
                ->withErrors(['track_id' => 'Submission period has closed.'])
                ->withInput();
        }

        $keywordsArray = [];
        if (! empty($validated['keywords'])) {
            $keywordsArray = array_filter(array_map('trim', explode(',', $validated['keywords'])));
        }

        $submission = Submission::create([
            'conference_id' => $validated['conference_id'],
            'track_id' => $validated['track_id'],
            'user_id' => $request->user()->id,
            'title' => $validated['title'],
            'abstract' => $validated['abstract'],
            'keywords' => $keywordsArray,
            'status' => SubmissionStatus::Draft,
        ]);

        if (! empty($validated['co_authors'])) {
            $this->syncCoAuthors($submission, $validated['co_authors']);
        }

        return redirect()->route('submissions.show', $submission)->with('success', 'Submission created successfully.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Submission $submission)
    {
        $this->authorize('view', $submission);

        $submission = $submission->load([
            'conference',
            'track',
            'files',
            'coAuthors',
            'assignments.reviewer',
            'bids.user',
            'decision.decidedBy',
            'reviews.reviewer',
        ]);

        if ($submission->decision->isNotEmpty()) {
            $submission->setRelation('decision', $submission->decision->last());
        } else {
            $submission->setRelation('decision', null);
        }

        $viewerRole = $this->viewerRole($request->user(), $submission);

        // Gate on the conference permission rather than on the viewerRole string: an
        // assigned reviewer is reported as 'reviewer' by viewerRole() but is still a
        // committee member who needs the roster to declare a conflict. An author who is
        // not on the committee never passes here.
        $isReviewerPool = $viewerRole === 'chair'
            || ($submission->conference !== null
                && $this->hasConferencePermission($request->user(), $submission->conference, 'submission:review'));

        app(PermissionRegistrar::class)->setPermissionsTeamId($submission->conference_id);
        $committeeMembers = $isReviewerPool
            ? User::role('committee_member')->get(['id', 'first_name', 'last_name', 'email'])
            : collect();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        $doubleBlind = $submission->conference?->double_blind_review ?? false;

        // D-11.1 / IN-13 — the anonymisation was in the Vue page, not in the payload, so
        // this route returned committee names+emails, COI reasons, bidder ids and every
        // review's reviewer identity to ANY authorised viewer, including the author of a
        // double-blind paper: the page rendered none of it, the devtools showed all of it.
        // Masking is server-side now, keyed off the role that was already computed, and the
        // COI screen is not even run for a viewer entitled to none of it.
        if (! $isReviewerPool) {
            foreach ($submission->reviews as $review) {
                $review->setRelation('reviewer', null);
            }
            foreach ($submission->assignments as $assignment) {
                $assignment->setRelation('reviewer', null);
            }
            $submission->setRelation('bids', collect());
        }

        $coi = app(ConflictOfInterestService::class);
        $coiFlags = [];
        foreach ($committeeMembers as $member) {
            $conflict = ReviewerConflict::where('submission_id', $submission->id)
                ->where('reviewer_id', $member->id)
                ->first();
            $auto = $coi->analyze($submission, $member);

            $coiFlags[$member->id] = [
                'declared' => $coi->hasDeclared($submission, $member),
                'auto_level' => $auto->level,
                'auto_reason' => $auto->reason,
                'overridable' => $auto->overridable,
                'overridden' => $conflict?->overridden ?? false,
            ];
        }

        return Inertia::render('Submissions/Show', [
            'submission' => $submission,
            'committee_members' => $committeeMembers,
            'double_blind_review' => $doubleBlind,
            'coi_flags' => $coiFlags,
            'bidder_ids' => $isReviewerPool ? $submission->bids->pluck('user_id')->all() : [],
            'viewer_role' => $viewerRole,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Submission $submission)
    {
        $this->authorize('update', $submission);

        return Inertia::render('Submissions/Edit', [
            'submission' => $submission->load('conference', 'track', 'files', 'coAuthors'),
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Submission $submission)
    {
        $this->authorize('update', $submission);

        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'abstract' => 'required|string',
            'keywords' => 'nullable|string|max:255',
            'co_authors' => 'nullable|array',
            'co_authors.*.id' => 'nullable|exists:submission_coauthors,id',
            'co_authors.*.first_name' => 'required|string|max:255',
            'co_authors.*.last_name' => 'required|string|max:255',
            'co_authors.*.email' => 'required|email|max:255',
        ]);

        $keywordsArray = [];
        if (! empty($validated['keywords'])) {
            $keywordsArray = array_filter(array_map('trim', explode(',', $validated['keywords'])));
        }

        $submission->update([
            'title' => $validated['title'],
            'abstract' => $validated['abstract'],
            'keywords' => $keywordsArray,
        ]);

        $this->syncCoAuthors($submission, $validated['co_authors'] ?? []);

        if ($submission->status === SubmissionStatus::Submitted) {
            $submission->update(['status' => SubmissionStatus::Draft]);
        }

        return redirect()->route('submissions.show', $submission)->with('success', 'Submission updated successfully.');
    }

    /**
     * Submit the specified resource for review.
     */
    public function submit(Request $request, Submission $submission)
    {
        $this->authorize('submit', $submission);

        $conference = $submission->conference;
        $track = $conference->tracks()->find($submission->track_id);

        $now = now();
        $cfpOpensAt = $track->phaseOpensAt(Phase::Submission);
        if ($cfpOpensAt && $now < $cfpOpensAt) {
            return redirect()->back()
                ->withErrors(['track_id' => 'Submission period has not started yet.'])
                ->withInput();
        }

        $cfpClosesAt = $track->phaseClosesAt(Phase::Submission);
        if ($cfpClosesAt && $now > $cfpClosesAt) {
            return redirect()->back()
                ->withErrors(['track_id' => 'Submission period has closed.'])
                ->withInput();
        }

        $submission->update(['status' => SubmissionStatus::Submitted]);

        app(PermissionRegistrar::class)->setPermissionsTeamId($submission->conference_id);
        $chairs = User::role('chair')->get();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        foreach ($chairs as $chair) {
            $chair->notify(new SubmissionReceived($submission));
        }

        return redirect()->route('submissions.show', $submission)->with('success', 'Submission sent for review.');
    }

    /**
     * Move a submitted submission into review (AD-009). Chair-only; the state
     * gate lives in SubmissionPolicy::startReview, so a wrong status is a 403.
     * Deliberately no window check (SD-20 owns window gates) and no notification.
     */
    public function startReview(Submission $submission)
    {
        $this->authorize('startReview', $submission);

        $submission->update(['status' => SubmissionStatus::UnderReview]);

        return back()->with('success', 'Submission moved to review.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Submission $submission)
    {
        $this->authorize('delete', $submission);

        $submission->delete();

        return redirect()->route('submissions.index')->with('success', 'Submission retracted successfully.');
    }

    /**
     * Assign a reviewer to a submission.
     */
    public function assignReviewer(Request $request, Submission $submission)
    {
        $this->authorize('update', $submission);

        if ($submission->status === SubmissionStatus::Draft) {
            return back()->withErrors(['submission' => 'Cannot assign a reviewer to a draft submission.']);
        }

        // AD-012 / SD-05 — the two inline `review_close_at`/`review_open_at` comparisons
        // are DELETED, not supplemented (§R12/B-2): a stricter check stacked above
        // reviewable() would be a second, weaker gate that deadlocks round-2 assignment
        // forever, because the six-boundary ordering forces review_close_at <=
        // decision_open_at. One predicate now covers all four reviewer-facing sites.
        if (! PhaseGuards::reviewable($submission)) {
            return back()->withErrors(['reviewer_id' => PhaseGuards::message($submission)]);
        }

        $validated = $request->validate([
            'reviewer_id' => 'required|exists:users,id',
            'assignment_id' => 'nullable|exists:review_assignments,id',
        ]);

        $reviewer = User::findOrFail($validated['reviewer_id']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($submission->conference_id);
        $isCommitteeMember = $reviewer->hasRole('committee_member');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        if (! $isCommitteeMember) {
            return back()->withErrors(['reviewer_id' => 'Selected user is not a committee member of this conference.']);
        }

        $coi = app(ConflictOfInterestService::class);

        // Declared conflicts are authoritative and can never be overridden.
        if ($coi->hasDeclared($submission, $reviewer)) {
            return back()->withErrors([
                'reviewer_id' => 'This reviewer has a declared conflict of interest with this submission and cannot be assigned.',
            ]);
        }

        $coiResult = $coi->check($submission, $reviewer);

        // Self-review rule: reviewer is an author of the submission. Never
        // overridable, regardless of the override flag.
        if ($coiResult->isHard() && ! $coiResult->overridable) {
            return back()->withErrors([
                'reviewer_id' => 'This reviewer is an author of this submission and cannot review it.',
            ]);
        }

        // Auto-detected hard conflict: block unless the chair overrides.
        if ($coiResult->isHard() && ! $request->boolean('override_conflict')) {
            return back()->withErrors([
                'reviewer_id' => 'This reviewer has a potential conflict of interest with this submission. Use "assign anyway" to override.',
            ]);
        }

        if ($coiResult->isHard() && $request->boolean('override_conflict')) {
            $coi->override($submission, $reviewer);
        }

        $flash = ['success' => 'Reviewer assigned successfully.'];
        if ($coiResult->isSoft()) {
            $flash['warning'] = 'Reviewer has a flagged (soft) conflict of interest; assigned anyway.';
        }

        $maxReviewers = (int) config('conference.max_reviewers', 5);

        // Reassigning replaces a specific assignment, so drop it before the
        // cap check so the count reflects the net change.
        if (! empty($validated['assignment_id'])) {
            $reassigning = ReviewAssignment::where('submission_id', $submission->id)
                ->find($validated['assignment_id']);

            if ($reassigning) {
                $reassigning->delete();
            }
        }

        $existingAssignment = ReviewAssignment::where('submission_id', $submission->id)
            ->where('reviewer_id', $validated['reviewer_id'])
            ->first();

        $remaining = ReviewAssignment::remainingReviewerSlots($submission);

        // Only block when adding a genuinely new assignment would exceed the cap.
        // Counts ACTIVE seats (pending + accepted), so a declined row re-invited back
        // to pending costs nothing and a stale declined row cannot block the chair.
        if (! $existingAssignment && $remaining <= 0) {
            return back()->withErrors([
                'reviewer_id' => "Maximum of {$maxReviewers} reviewers already assigned to this submission.",
            ]);
        }

        $assignment = ReviewAssignment::updateOrCreate(
            ['submission_id' => $submission->id, 'reviewer_id' => $validated['reviewer_id']],
            [
                'status' => ReviewAssignmentStatus::Pending,
                'assigned_at' => now(),
            ]
        );

        // Reviewers now exist, so the paper is under review (AD-009). Idempotent
        // for any status past Submitted.
        if ($submission->status === SubmissionStatus::Submitted) {
            $submission->update(['status' => SubmissionStatus::UnderReview]);
        }

        $reviewer->notify(new ReviewerAssigned($submission));

        return back()->with($flash);
    }

    /**
     * Auto-assign the committee members who bid on a submission, up to the
     * max-reviewer cap, skipping conflicted reviewers via the COI service.
     */
    public function autoAssignBidders(Request $request, Submission $submission)
    {
        $this->authorize('update', $submission);

        if ($submission->status === SubmissionStatus::Draft) {
            return back()->withErrors(['submission' => 'Cannot auto-assign on a draft submission.']);
        }

        // Same predicate as assignReviewer(); the bare `review period has closed`
        // refusal is gone (§R12/B-2) so a revision round can be re-staffed.
        if (! PhaseGuards::reviewable($submission)) {
            return back()->withErrors(['reviewer_id' => PhaseGuards::message($submission)]);
        }

        $coi = app(ConflictOfInterestService::class);
        $maxReviewers = (int) config('conference.max_reviewers', 5);
        $override = $request->boolean('override_conflict');

        $bidders = SubmissionBid::where('submission_id', $submission->id)
            ->with('user')
            ->get()
            ->map(fn ($bid) => $bid->user)
            ->filter(fn ($bidder) => $bidder !== null);

        $assigned = 0;
        foreach ($bidders as $bidder) {
            // Live recount against active seats, not against this run's insert
            // counter — the old `$assigned` started at zero on every call, so a
            // submission with four accepted reviews could gain five more (D-5a.1).
            if (ReviewAssignment::remainingReviewerSlots($submission) <= 0) {
                break;
            }

            $existing = ReviewAssignment::where('submission_id', $submission->id)
                ->where('reviewer_id', $bidder->id)
                ->first();
            if ($existing && in_array($existing->status, [
                ReviewAssignmentStatus::Pending,
                ReviewAssignmentStatus::Accepted,
            ], true)) {
                continue;
            }

            // COI filter: declared always excluded; auto-hard excluded unless
            // the chair overrides (now or previously); self-review (hard and
            // non-overridable) always excluded.
            if ($coi->hasDeclared($submission, $bidder)) {
                continue;
            }

            $coiResult = $coi->analyze($submission, $bidder);
            if ($coiResult->isHard() && ! $coiResult->overridable) {
                continue;
            }

            $overridden = ReviewerConflict::where('submission_id', $submission->id)
                ->where('reviewer_id', $bidder->id)
                ->whereIn('level', ['hard', 'soft'])
                ->where('overridden', true)
                ->exists();

            if ($coiResult->isHard() && ! $override && ! $overridden) {
                continue;
            }

            ReviewAssignment::updateOrCreate(
                ['submission_id' => $submission->id, 'reviewer_id' => $bidder->id],
                [
                    'status' => ReviewAssignmentStatus::Pending,
                    'assigned_at' => now(),
                ]
            );

            $bidder->notify(new ReviewerAssigned($submission));

            // The bid has served its purpose; withdraw it so it is not
            // re-assigned or shown as an open bid afterwards.
            SubmissionBid::where('submission_id', $submission->id)
                ->where('user_id', $bidder->id)
                ->delete();

            $assigned++;
        }

        if ($assigned === 0) {
            return back()->with('warning', 'No eligible bidders were auto-assigned (conflicts or cap).');
        }

        // At least one assignment landed, so the paper is under review (AD-009).
        if ($submission->status === SubmissionStatus::Submitted) {
            $submission->update(['status' => SubmissionStatus::UnderReview]);
        }

        return back()->with('success', "Auto-assigned {$assigned} reviewer(s) from bids.");
    }

    /**
     * Assignment overview: the reviewer's own inbox (invitations awaiting
     * accept/decline plus accepted work) and, for chairs, every assignment
     * across the conferences they chair, so they can review and mass-fix
     * anything auto-assign produced.
     *
     * Invitations used to surface on `reviews.index`, which mixed them with
     * the reviews a reviewer is expected to write; the split keeps
     * `/assignments` = "what you were asked to do" and `/reviews` = "review
     * work you have taken on".
     */
    public function assignmentOverview(Request $request)
    {
        $user = $request->user();

        $conferenceIds = [];
        foreach (Conference::all() as $conference) {
            app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
            // Re-query roles per conference: `hasRole()` caches the roles
            // relation after the first iteration, so later conferences
            // would otherwise be missed for chairs scoped to a later one.
            $roles = $user->roles()->pluck('name')->toArray();
            if (in_array('chair', $roles, true)) {
                $conferenceIds[] = $conference->id;
            }
            app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        }
        $user->unsetRelation('roles');

        $reviewerAssignments = ReviewAssignment::where('reviewer_id', $user->id)
            ->whereIn('status', [
                ReviewAssignmentStatus::Pending,
                ReviewAssignmentStatus::Accepted,
            ])
            ->with(['submission.conference', 'submission.track', 'reviews'])
            ->orderByRaw(
                'case when status = ? then 0 else 1 end',
                [ReviewAssignmentStatus::Pending->value],
            )
            ->orderByDesc('assigned_at')
            ->get();

        $assignments = ReviewAssignment::whereHas('submission', fn ($q) => $q->whereIn('conference_id', $conferenceIds))
            ->with(['submission.conference', 'submission.track', 'reviewer'])
            ->orderByDesc('assigned_at')
            ->get();

        return Inertia::render('Assignments/Index', [
            'reviewer_assignments' => $reviewerAssignments,
            'assignments' => $assignments,
        ]);
    }

    /**
     * Record a declared conflict of interest between a reviewer and an
     * author of the submission.
     */
    public function declareConflict(Request $request, Submission $submission)
    {
        $this->authorize('update', $submission);

        $validated = $request->validate([
            'reviewer_id' => 'required|exists:users,id',
            'author_id' => 'nullable|exists:users,id',
            'coauthor_id' => 'nullable|exists:submission_coauthors,id',
            'reason' => 'nullable|string|max:1000',
        ]);

        if (empty($validated['author_id']) && empty($validated['coauthor_id'])) {
            return back()->withErrors([
                'author_id' => 'Choose an author to declare the conflict against.',
            ]);
        }

        $reviewer = User::findOrFail($validated['reviewer_id']);

        app(PermissionRegistrar::class)->setPermissionsTeamId($submission->conference_id);
        $isCommitteeMember = $reviewer->hasRole('committee_member');
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        if (! $isCommitteeMember) {
            return back()->withErrors(['reviewer_id' => 'Selected user is not a committee member of this conference.']);
        }

        $coi = app(ConflictOfInterestService::class);

        $coi->recordDeclared(
            $submission,
            $reviewer,
            isset($validated['author_id']) ? User::find($validated['author_id']) : null,
            isset($validated['coauthor_id']) ? SubmissionCoAuthor::find($validated['coauthor_id']) : null,
            $validated['reason'] ?? null,
            $request->user()
        );

        return back()->with('success', 'Conflict of interest declared.');
    }

    /**
     * Override an auto-detected conflict of interest for a reviewer.
     */
    public function overrideConflict(Request $request, Submission $submission, User $reviewer)
    {
        $this->authorize('update', $submission);

        $coi = app(ConflictOfInterestService::class);

        if (! $coi->override($submission, $reviewer)) {
            return back()->withErrors([
                'reviewer_id' => 'No overridable conflict found for this reviewer.',
            ]);
        }

        return back()->with('success', 'Conflict of interest overridden.');
    }

    /**
     * Create, update, or remove co-author rows to match the incoming list.
     * A registered user is resolved by email match; unregistered co-authors
     * are emailed an invitation once.
     */
    private function syncCoAuthors(Submission $submission, array $coAuthors): void
    {
        $existing = $submission->coAuthors()->get()->keyBy('id');
        $keptIds = [];

        foreach ($coAuthors as $data) {
            $user = User::where('email', $data['email'])->first();

            $coAuthor = $existing->get($data['id'] ?? null);
            if ($coAuthor) {
                $userChanged = $coAuthor->user_id != $user?->id;
                $emailChanged = $coAuthor->email !== $data['email'];

                $coAuthor->update([
                    'user_id' => $user?->id,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                    'invitation_sent_at' => ($emailChanged || $userChanged)
                        ? null
                        : $coAuthor->invitation_sent_at,
                    'invitation_accepted_at' => $userChanged ? null : $coAuthor->invitation_accepted_at,
                ]);
            } else {
                $coAuthor = SubmissionCoAuthor::create([
                    'submission_id' => $submission->id,
                    'user_id' => $user?->id,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'email' => $data['email'],
                ]);
            }

            $this->invalidateSelfReviews($coAuthor);

            if ($coAuthor->user_id === null && $coAuthor->invitation_sent_at === null) {
                $coAuthor->invitation_sent_at = now();
                $coAuthor->save();

                Notification::route('mail', $coAuthor->email)
                    ->notify(new CoAuthorInvitation($submission));
            }

            $keptIds[] = $coAuthor->id;
        }

        $submission->coAuthors()->whereNotIn('id', $keptIds)->delete();
    }

    /**
     * If a registered co-author is also a reviewer on the same submission,
     * invalidate that assignment so they are not reviewing their own paper.
     */
    private function invalidateSelfReviews(SubmissionCoAuthor $coAuthor): void
    {
        if ($coAuthor->user_id === null) {
            return;
        }

        ReviewAssignment::where('submission_id', $coAuthor->submission_id)
            ->where('reviewer_id', $coAuthor->user_id)
            ->whereIn('status', [ReviewAssignmentStatus::Pending, ReviewAssignmentStatus::Accepted])
            ->update(['status' => ReviewAssignmentStatus::Declined]);
    }

    /**
     * Determine the viewer's relationship to the submission for double-blind
     * anonymization on the submission show page.
     */
    private function viewerRole(User $user, Submission $submission): string
    {
        if ($this->isGlobalAdmin($user)
            || ($submission->conference && $this->hasConferencePermission($user, $submission->conference, 'conference:manage'))) {
            return 'chair';
        }

        if ($submission->assignments()
            ->where('reviewer_id', $user->id)
            ->where('status', ReviewAssignmentStatus::Accepted)
            ->exists()) {
            return 'reviewer';
        }

        if ($user->id === $submission->user_id) {
            return 'author';
        }

        if ($submission->coAuthors()->where('user_id', $user->id)->exists()) {
            return 'author';
        }

        return 'committee';
    }

    /**
     * Determine whether the user is a global admin, who can manage any
     * conference and therefore always sees full identity in double-blind mode.
     */
    private function isGlobalAdmin(User $user): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId(0);
        $isAdmin = $user->roles()->where('name', 'admin')->exists();
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        $user->unsetRelation('roles');

        return $isAdmin;
    }

    /**
     * Check the user's permission scoped to the given conference team.
     */
    private function hasConferencePermission(User $user, Conference $conference, string $permission): bool
    {
        app(PermissionRegistrar::class)->setPermissionsTeamId($conference->id);
        $hasPermission = $user->hasPermissionTo($permission);
        app(PermissionRegistrar::class)->setPermissionsTeamId(null);

        return $hasPermission;
    }
}
