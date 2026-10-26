<?php

namespace App\Models;

use App\Enums\DecisionOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Decision extends Model
{
    protected $fillable = [
        'submission_id',
        'decided_by_id',
        'decision',
        'rationale',
        'revision_round',
    ];

    protected $casts = [
        'decision' => DecisionOutcome::class,
        'revision_round' => 'integer',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_id');
    }
}
