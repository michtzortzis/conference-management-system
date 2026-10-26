<?php

namespace App\Models;

use App\Enums\SubmissionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Submission extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'conference_id',
        'track_id',
        'user_id',
        'title',
        'abstract',
        'keywords',
        'status',
    ];

    protected $casts = [
        'status' => SubmissionStatus::class,
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
        'keywords' => 'array',
    ];

    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    public function track(): BelongsTo
    {
        return $this->belongsTo(Track::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(SubmissionFile::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ReviewAssignment::class);
    }

    public function getAssignmentAttribute(): ?ReviewAssignment
    {
        return $this->assignments->first();
    }

    public function decision(): HasMany
    {
        return $this->hasMany(Decision::class);
    }

    public function coAuthors(): HasMany
    {
        return $this->hasMany(SubmissionCoAuthor::class);
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(ReviewerConflict::class);
    }

    public function bids(): HasMany
    {
        return $this->hasMany(SubmissionBid::class);
    }

    public function bidders(): HasMany
    {
        return $this->hasMany(SubmissionBid::class)->with('user');
    }

    public function declaredConflicts(): HasMany
    {
        return $this->hasMany(ReviewerConflict::class)->where('level', 'declared');
    }

    public function autoConflicts(): HasMany
    {
        return $this->hasMany(ReviewerConflict::class)->whereIn('level', ['hard', 'soft']);
    }
}
