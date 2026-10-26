<?php

namespace App\Http\Controllers;

use App\Enums\Phase;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\ReviewRecommendation;
use App\Models\Conference;
use App\Models\Review;
use App\Models\ReviewAssignment;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\ReviewCompleted;
use App\Notifications\ReviewWindowClosing;
use App\Services\ConflictOfInterestService;
use App\Support\PhaseGuards;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Spatie\Permission\PermissionRegistrar;

class ReviewController extends Controller
{
    use AuthorizesRequests;

    /**
     * Display a listing of the resource.
     *
     * Only assignments the reviewer has accepted: an invitation the reviewer has
     * not yet answered lives on `assignments.index` (the inbox), so this page is
     * strictly the review work they took on.
     */
    public function index(Request $request)
    {
        $assignments = ReviewAssignment::where('reviewer_id', $request->user()->id)
            ->where('status', ReviewAssignmentStatus::Accepted)
            ->with(['submission.conference', 'submission.track', 'reviews'])
            ->orderBy('assigned_at', 'desc')
            ->get();

        return Inertia::render('Reviews/Index', [
            'assignments' => $assignments,
        ]);
    }

    /**
     * Accept a review assignment.
     */
    public function accept(Request $request, ReviewAssignment $assignment)
    {
        $this->authorize('accept', $assignment);

        $assignment->update([
            'status' => ReviewAssignmentStatus::Accepted,
            'accepted_at' => now(),
        ]);

        $reviewClosesAt = $assignment->submission->track?->phaseClosesAt(Phase::Review);
        if ($reviewClosesAt) {
            $request->user()->notify(new ReviewWindowClosing(
                $assignment->submission,
                $reviewClosesAt->format('Y-m-d H:i'),
            ));
        }

        return back()->with('success', 'Assignment accepted.');
    }

    /**
     * Decline a review assignment.
     */
    public function decline(Request $request, ReviewAssignment $assignment)
    {
        $this->authorize('decline', $assignment);

        $assignment->update([
            'status' => ReviewAssignmentStatus::Declined,
            'declined_at' => now(),
        ]);

        return back()->with('success', 'Assignment declined.');
    }

    /**
     * A reviewer self-declares a conflict of interest against a submission they
     * are assigned to. Works in double-blind review: the reviewer declares
     * without seeing the author identity. The assignment is withdrawn so the
     * chair can reassign.
     */
    public function selfDeclareConflict(Request $request, ReviewAssignment $assignment)
    {
        $this->authorize('selfDeclare', $assignment);

        $validated = $request->validate([
            'reason' => 'nullable|string|max:1000',
        ]);

        app(ConflictOfInterestService::class)->selfDeclare(
            $assignment->submission,
            $assignment->reviewer,
            $validated['reason'] ?? null
        );

        $assignment->update([
            'status' => ReviewAssignmentStatus::Declined,
            'declined_at' => now(),
        ]);

        return back()->with('success', 'Conflict of interest declared; assignment withdrawn.');
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(Request $request)
    {
        $assignment = ReviewAssignment::whereId($request->assignment_id)
            ->where('reviewer_id', $request->user()->id)
            ->with('submission.conference', 'submission.track', 'submission.files')
            ->firstOrFail();

        if ($assignment->status !== ReviewAssignmentStatus::Accepted) {
            return back()->withErrors(['assignment_id' => 'You must accept the assignment before writing a review.']);
        }

        $submission = $assignment->submission;
        $doubleBlind = $this->isDoubleBlind($submission);

        $track = $submission->track;
        $now = now();
        $reviewWindowOpen = true;
        $reviewOpensAt = $track?->phaseOpensAt(Phase::Review);
        if ($reviewOpensAt && $now->lt($reviewOpensAt)) {
            $reviewWindowOpen = false;
        }

        $reviewClosesAt = $track?->phaseClosesAt(Phase::Review);
        if ($reviewClosesAt && $now->gt($reviewClosesAt)) {
            $reviewWindowOpen = false;
        }

        if (! $doubleBlind || $this->isChair($request->user(), $submission)) {
            $submission->load('user');
        } else {
            $submission->setRelation('user', null);
        }

        return Inertia::render('Reviews/Create', [
            'assignment' => $assignment,
            'double_blind' => $doubleBlind,
            'review_window_open' => $reviewWindowOpen,
        ]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $submission = Submission::findOrFail($request->submission_id);
        $this->authorize('create', [Review::class, $submission]);

        $validated = $request->validate([
            'submission_id' => 'required|exists:submissions,id',
            'assignment_id' => 'required|exists:review_assignments,id',
            'recommendation' => ['required', Rule::in(ReviewRecommendation::cases())],
            'comments' => 'nullable|string',
            'grade' => 'required|integer|min:1|max:5',
        ]);

        $assignment = ReviewAssignment::whereId($validated['assignment_id'])
            ->where('reviewer_id', $request->user()->id)
            ->firstOrFail();

        if ($assignment->status !== ReviewAssignmentStatus::Accepted) {
            return back()->withErrors(['assignment_id' => 'You must accept the assignment before writing a review.']);
        }

        // SD-05 / AD-012 — the soft gate becomes hard. `create()` only ever *computed*
        // `review_window_open` as a prop, so the UI hid a button the server accepted
        // (IN-15 / D12). One shared predicate, and it accepts an open revision round
        // so round-2 work is not deadlocked by `review_close_at`.
        if (! PhaseGuards::reviewable($submission)) {
            return back()->withErrors(['submission' => PhaseGuards::message($submission)]);
        }

        $review = Review::create($validated + ['reviewer_id' => $request->user()->id]);

        $submission->user->notify(new ReviewCompleted($review));

        return redirect()->route('reviews.show', $review);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Review $review)
    {
        $this->authorize('view', $review);

        $submission = $review->submission;
        $doubleBlind = $this->isDoubleBlind($submission);
        $viewerRole = $this->viewerRole($request->user(), $submission, $review);

        if ($doubleBlind && $viewerRole === 'committee') {
            $submission->setRelation('user', null);
            $review->setRelation('reviewer', null);
            $review->load('submission.conference', 'submission.track', 'submission.files');
        } elseif ($doubleBlind && $viewerRole === 'reviewer') {
            $submission->setRelation('user', null);
            $review->load('submission.conference', 'submission.track', 'submission.files', 'reviewer');
        } elseif ($doubleBlind && $viewerRole === 'author') {
            $review->setRelation('reviewer', null);
            $review->load('submission.user', 'submission.conference', 'submission.track', 'submission.files');
        } else {
            $review->load('submission.user', 'submission.conference', 'submission.track', 'submission.files', 'reviewer');
        }

        return Inertia::render('Reviews/Show', [
            'review' => $review,
            'double_blind' => $doubleBlind,
            'viewer_role' => $viewerRole,
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Request $request, Review $review)
    {
        $this->authorize('update', $review);

        $submission = $review->submission;
        $doubleBlind = $this->isDoubleBlind($submission);

        if ($doubleBlind && ! $this->isChair($request->user(), $submission)) {
            $submission->setRelation('user', null);
            $review->load('submission.conference', 'submission.track', 'submission.files', 'reviewer');
        } else {
            $review->load('submission.user', 'submission.conference', 'submission.track', 'submission.files', 'reviewer');
        }

        return Inertia::render('Reviews/Edit', [
            'review' => $review,
            'double_blind' => $doubleBlind,
        ]);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Review $review)
    {
        $this->authorize('update', $review);

        // D-8.3: editing a review is a review write, so it carries the same gate as
        // store() — a split would make "update" the hole in the window (AD-012).
        if (! PhaseGuards::reviewable($review->submission)) {
            return back()->withErrors(['submission' => PhaseGuards::message($review->submission)]);
        }

        $validated = $request->validate([
            'recommendation' => ['required', Rule::in(ReviewRecommendation::cases())],
            'comments' => 'nullable|string',
            'grade' => 'required|integer|min:1|max:5',
        ]);

        $review->update($validated);

        $review->submission->user->notify(new ReviewCompleted($review));

        return redirect()->route('reviews.show', $review);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Review $review)
    {
        $this->authorize('delete', $review);

        $review->delete();

        return redirect()->route('reviews.index')
            ->with('success', 'Review deleted successfully.');
    }

    /**
     * Determine whether the submission's conference uses double-blind review.
     */
    private function isDoubleBlind(Submission $submission): bool
    {
        return $submission->conference?->double_blind_review ?? false;
    }

    /**
     * Determine the viewer's relationship to the submission for double-blind
     * anonymization. Chairs always see full identity; reviewers lose the author
     * identity; authors lose the reviewer identity; unrelated viewers lose both.
     */
    private function viewerRole(User $user, Submission $submission, ?Review $review = null): string
    {
        if ($this->isChair($user, $submission)) {
            return 'chair';
        }

        if ($review && $user->id === $review->reviewer_id) {
            return 'reviewer';
        }

        if ($user->id === $submission->user_id) {
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
     * Determine whether the user has conference management permission, i.e. is
     * a chair for the submission's conference.
     */
    private function isChair(User $user, Submission $submission): bool
    {
        return $this->isGlobalAdmin($user)
            || ($submission->conference && $this->hasConferencePermission($user, $submission->conference, 'conference:manage'));
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
