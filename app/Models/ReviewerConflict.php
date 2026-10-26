<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewerConflict extends Model
{
    protected $fillable = [
        'submission_id',
        'reviewer_id',
        'author_id',
        'coauthor_id',
        'level',
        'reason',
        'overridden',
        'created_by_id',
    ];

    protected $casts = [
        'overridden' => 'boolean',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function coAuthor(): BelongsTo
    {
        return $this->belongsTo(SubmissionCoAuthor::class, 'coauthor_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
