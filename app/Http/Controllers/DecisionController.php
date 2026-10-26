<?php

namespace App\Http\Controllers;

use App\Enums\DecisionOutcome;
use App\Enums\Phase;
use App\Enums\SubmissionStatus;
use App\Models\Decision;
use App\Models\Submission;
use App\Notifications\DecisionMade;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Enum;

class DecisionController extends Controller
{
    use AuthorizesRequests;

    public function store(Request $request, Submission $submission)
    {
        $this->authorize('store', new Decision(['submission_id' => $submission->id]));

        $validated = $request->validate([
            'decision' => ['required', new Enum(DecisionOutcome::class)],
            'rationale' => 'nullable|string',
        ]);

        $decisionEnum = DecisionOutcome::from($validated['decision']);

        $track = $submission->track;
        $now = now();
        $decisionOpensAt = $track?->phaseOpensAt(Phase::Decision);
        if ($decisionOpensAt && $now < $decisionOpensAt) {
            return back()->withErrors(['decision' => 'Decision window has not opened yet.']);
        }

        $decisionClosesAt = $track?->phaseClosesAt(Phase::Decision);
        if ($decisionClosesAt && $now > $decisionClosesAt) {
            return back()->withErrors(['decision' => 'Decision window has closed.']);
        }

        if (! in_array($submission->status->value, ['under_review', 'minor_revision', 'major_revision'])) {
            return back()->withErrors(['decision' => 'Decisions can only be made on submissions under review or under revision.']);
        }

        if (in_array($submission->status->value, ['minor_revision', 'major_revision']) &&
            ! in_array($decisionEnum->value, ['accepted', 'rejected'])) {
            return back()->withErrors(['decision' => 'Only accepted or rejected decisions are allowed for submissions under revision.']);
        }

        $revisionRound = $submission->decision()->count() + 1;

        $decision = Decision::create([
            'submission_id' => $submission->id,
            'decided_by_id' => $request->user()->id,
            'decision' => $decisionEnum,
            'rationale' => $validated['rationale'] ?? null,
            'revision_round' => $revisionRound,
        ]);

        $this->transitionStatus($submission, $decisionEnum);

        $submission->user->notify(new DecisionMade($decision));

        foreach ($submission->coAuthors()->whereNotNull('user_id')->with('user')->get() as $coAuthor) {
            $coAuthor->user->notify(new DecisionMade($decision));
        }

        return redirect()->back()->with('success', 'Decision recorded successfully.');
    }

    private function transitionStatus(Submission $submission, DecisionOutcome $decision): void
    {
        match ($decision) {
            DecisionOutcome::Accepted => $submission->update(['status' => SubmissionStatus::Accepted]),
            DecisionOutcome::Rejected => $submission->update(['status' => SubmissionStatus::Rejected]),
            DecisionOutcome::MinorRevision => $submission->update(['status' => SubmissionStatus::MinorRevision]),
            DecisionOutcome::MajorRevision => $submission->update(['status' => SubmissionStatus::MajorRevision]),
        };
    }
}
