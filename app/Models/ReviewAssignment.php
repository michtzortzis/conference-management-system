<?php

namespace App\Models;

use App\Enums\ReviewAssignmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewAssignment extends Model
{
    protected $fillable = [
        'submission_id',
        'reviewer_id',
        'status',
        'assigned_at',
        'accepted_at',
        'declined_at',
    ];

    protected $casts = [
        'status' => ReviewAssignmentStatus::class,
        'assigned_at' => 'datetime',
        'accepted_at' => 'datetime',
        'declined_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class, 'assignment_id');
    }

    /**
     * Assignments that still occupy a reviewer seat on this submission.
     *
     * A `declined` row is re-invitable and a `completed` one is out of the pool, so
     * counting every row (what `assignReviewer` used to do) blocks the chair too early,
     * while counting this run's inserts (what `autoAssignBidders` used to do) let the
     * cap be exceeded. D-5a.1: one predicate, both paths.
     */
    public static function activeCount(Submission $submission): int
    {
        return static::query()
            ->where('submission_id', $submission->id)
            ->whereIn('status', [
                ReviewAssignmentStatus::Pending->value,
                ReviewAssignmentStatus::Accepted->value,
            ])
            ->count();
    }

    /**
     * Seats left under `conference.max_reviewers` (D8).
     */
    public static function remainingReviewerSlots(Submission $submission): int
    {
        $max = (int) config('conference.max_reviewers', 5);

        return max(0, $max - static::activeCount($submission));
    }
}
