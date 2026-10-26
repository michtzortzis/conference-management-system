<?php

namespace App\Services;

use App\Models\ReviewerConflict;
use App\Models\Submission;
use App\Models\SubmissionCoAuthor;
use App\Models\User;

/**
 * Two-layer conflict-of-interest detection for review assignment.
 *
 * DECLARED conflicts are authoritative and always hard-block; they are never
 * overridable. AUTO-DETECTED conflicts use a weighted scoring model against
 * every author (submitter + co-authors) and are a conference-level toggle.
 */
class ConflictOfInterestService
{
    /**
     * Compute and persist the auto-detected conflict state for a reviewer.
     */
    public function check(Submission $submission, User $reviewer): CoiResult
    {
        $result = $this->analyze($submission, $reviewer);

        if ($result->hasConflict()) {
            $this->persist(
                $submission,
                $reviewer,
                $result->level,
                $result->reason,
                $result->authorId,
                $result->coauthorId
            );
        }

        return $result;
    }

    /**
     * Compute the auto-detected conflict state without persisting it.
     */
    public function analyze(Submission $submission, User $reviewer): CoiResult
    {
        // SELF-REVIEW hard rule: the reviewer is the submitter or any
        // registered co-author. Explicit hard gate, never overridable.
        if ($reviewer->id === $submission->user_id
            || $submission->coAuthors()->where('user_id', $reviewer->id)->exists()) {
            return new CoiResult(
                'hard',
                'Self-review: reviewer is an author of this submission.',
                false
            );
        }

        // A declared conflict is authoritative and takes precedence.
        if ($this->hasDeclared($submission, $reviewer)) {
            return new CoiResult;
        }

        $conference = $submission->conference;
        if (! $conference || ! $conference->coi_auto_detect) {
            return new CoiResult;
        }

        $freeDomains = array_map('strtolower', config('conference.coi_free_email_domains', []));

        $bestScore = 0;
        $bestAuthorId = null;
        $bestCoauthorId = null;
        $bestReason = null;

        $submitter = $submission->user;
        if ($submitter) {
            [$score, $reason] = $this->score(
                $reviewer,
                $submitter->email,
                $submitter->first_name,
                $submitter->last_name,
                $submitter->affiliations,
                $freeDomains
            );

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAuthorId = $submitter->id;
                $bestCoauthorId = null;
                $bestReason = $reason;
            }
        }

        foreach ($submission->coAuthors()->with('user')->get() as $coAuthor) {
            if ($coAuthor->user) {
                $user = $coAuthor->user;
                [$score, $reason] = $this->score(
                    $reviewer,
                    $user->email,
                    $user->first_name,
                    $user->last_name,
                    $user->affiliations,
                    $freeDomains
                );
                $authorId = $user->id;
                $coauthorId = null;
            } else {
                [$score, $reason] = $this->score(
                    $reviewer,
                    $coAuthor->email,
                    $coAuthor->first_name,
                    $coAuthor->last_name,
                    collect(),
                    $freeDomains
                );
                $authorId = null;
                $coauthorId = $coAuthor->id;
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestAuthorId = $authorId;
                $bestCoauthorId = $coauthorId;
                $bestReason = $reason;
            }
        }

        $hardThreshold = config('conference.coi_thresholds.hard_threshold', 4);
        $softThreshold = config('conference.coi_thresholds.soft_threshold', 2);

        if ($bestScore >= $hardThreshold) {
            return new CoiResult('hard', $bestReason, true, $bestScore, $bestAuthorId, $bestCoauthorId);
        }

        if ($bestScore >= $softThreshold) {
            return new CoiResult('soft', $bestReason, true, $bestScore, $bestAuthorId, $bestCoauthorId);
        }

        return new CoiResult;
    }

    /**
     * Authoritative declared-conflict check.
     */
    public function hasDeclared(Submission $submission, User $reviewer): bool
    {
        return ReviewerConflict::where('submission_id', $submission->id)
            ->where('reviewer_id', $reviewer->id)
            ->where('level', 'declared')
            ->exists();
    }

    /**
     * Record a declared conflict against a specific author (submitter,
     * registered co-author, or unregistered co-author).
     */
    public function recordDeclared(
        Submission $submission,
        User $reviewer,
        ?User $author,
        ?SubmissionCoAuthor $coAuthor,
        ?string $reason,
        User $createdBy
    ): ReviewerConflict {
        return ReviewerConflict::updateOrCreate(
            ['submission_id' => $submission->id, 'reviewer_id' => $reviewer->id],
            [
                'author_id' => $author?->id,
                'coauthor_id' => $coAuthor?->id,
                'level' => 'declared',
                'reason' => $reason,
                'overridden' => false,
                'created_by_id' => $createdBy->id,
            ]
        );
    }

    /**
     * Record a reviewer's self-declared conflict against a submission they have
     * been assigned to. Works in double-blind review: the reviewer declares
     * without naming a specific author, so author_id/coauthor_id stay null.
     */
    public function selfDeclare(Submission $submission, User $reviewer, ?string $reason): ReviewerConflict
    {
        return ReviewerConflict::updateOrCreate(
            ['submission_id' => $submission->id, 'reviewer_id' => $reviewer->id],
            [
                'author_id' => null,
                'coauthor_id' => null,
                'level' => 'declared',
                'reason' => $reason,
                'overridden' => false,
                'created_by_id' => $reviewer->id,
            ]
        );
    }

    /**
     * Override an auto-detected conflict. Declared conflicts can never be
     * overridden; returns false when the conflict is declared or absent.
     */
    public function override(Submission $submission, User $reviewer): bool
    {
        $conflict = ReviewerConflict::where('submission_id', $submission->id)
            ->where('reviewer_id', $reviewer->id)
            ->whereIn('level', ['hard', 'soft'])
            ->first();

        if ($conflict) {
            $conflict->update(['overridden' => true]);

            return true;
        }

        // No persisted row yet (e.g. the conflict was only analyzed, not
        // persisted, during bidding). Persist an overridden auto row.
        $result = $this->analyze($submission, $reviewer);

        if (! $result->isHard() && ! $result->isSoft()) {
            return false;
        }

        $this->persist(
            $submission,
            $reviewer,
            $result->level,
            $result->reason,
            $result->authorId,
            $result->coauthorId
        );

        ReviewerConflict::where('submission_id', $submission->id)
            ->where('reviewer_id', $reviewer->id)
            ->whereIn('level', ['hard', 'soft'])
            ->update(['overridden' => true]);

        return true;
    }

    /**
     * Weighted scoring of a reviewer against a single author.
     *
     * @return array{0:int,1:?string} [score, reason]
     */
    private function score(
        User $reviewer,
        string $authorEmail,
        string $authorFirstName,
        string $authorLastName,
        $authorAffiliations,
        array $freeDomains
    ): array {
        $thresholds = config('conference.coi_thresholds', []);
        $score = 0;
        $reasons = [];

        // email_exact — strong.
        if (strcasecmp($reviewer->email, $authorEmail) === 0) {
            $score += $thresholds['email_exact'];
            $reasons[] = 'email matches an author';
        }

        // Affiliation: name + department is the strongest affiliation signal.
        $affiliationDept = false;
        $affiliationName = false;
        foreach ($reviewer->affiliations as $reviewerAffiliation) {
            foreach ($authorAffiliations as $authorAffiliation) {
                if (strcasecmp($reviewerAffiliation->name, $authorAffiliation->name) === 0) {
                    $reviewerDept = $reviewerAffiliation->pivot?->department;
                    $authorDept = $authorAffiliation->pivot?->department;

                    if ($reviewerDept && $authorDept && $reviewerDept === $authorDept) {
                        $affiliationDept = true;
                    } else {
                        $affiliationName = true;
                    }
                }
            }
        }

        if ($affiliationDept) {
            $score += $thresholds['affiliation_dept'];
            $reasons[] = 'shares an affiliation and department with an author';
        } elseif ($affiliationName) {
            $score += $thresholds['affiliation'];
            $reasons[] = 'shares an affiliation with an author';
        }

        // Email domain: weak, and excluded for free providers.
        $reviewerDomain = $this->domainOf($reviewer->email);
        $authorDomain = $this->domainOf($authorEmail);
        $domainMatches = $reviewerDomain
            && $authorDomain
            && $reviewerDomain === $authorDomain
            && ! in_array($reviewerDomain, $freeDomains);

        $nameMatches = strcasecmp($reviewer->first_name, $authorFirstName) === 0
            && strcasecmp($reviewer->last_name, $authorLastName) === 0;

        if ($domainMatches) {
            $score += $thresholds['email_domain'];
            $reasons[] = "email domain ({$reviewerDomain}) matches an author";

            if ($nameMatches) {
                $score += $thresholds['name_domain'];
                $reasons[] = 'name matches an author on a shared domain';
            }
        } elseif ($nameMatches) {
            $score += $thresholds['name'];
            $reasons[] = 'name matches an author';
        }

        return [$score, $reasons ? implode('; ', $reasons) : null];
    }

    /**
     * Persist the auto-detected conflict row, preserving the declared level
     * and any existing override.
     */
    private function persist(
        Submission $submission,
        User $reviewer,
        string $level,
        ?string $reason,
        ?int $authorId,
        ?int $coauthorId
    ): void {
        $existing = ReviewerConflict::where('submission_id', $submission->id)
            ->where('reviewer_id', $reviewer->id)
            ->first();

        // A declared conflict is authoritative; never clobber it with auto.
        if ($existing && $existing->level === 'declared') {
            return;
        }

        ReviewerConflict::updateOrCreate(
            ['submission_id' => $submission->id, 'reviewer_id' => $reviewer->id],
            [
                'level' => $level,
                'reason' => $reason,
                'author_id' => $authorId,
                'coauthor_id' => $coauthorId,
                'overridden' => $existing?->overridden ?? false,
                'created_by_id' => $existing?->created_by_id,
            ]
        );
    }

    private function domainOf(string $email): ?string
    {
        $parts = explode('@', strtolower(trim($email)));

        if (count($parts) !== 2 || $parts[1] === '') {
            return null;
        }

        return $parts[1];
    }
}
