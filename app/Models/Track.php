<?php

namespace App\Models;

use App\Enums\Phase;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Track extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'conference_id',
        'name',
        'description',
        'submission_open_at',
        'submission_close_at',
        'review_open_at',
        'review_close_at',
        'decision_open_at',
        'decision_close_at',
    ];

    protected $casts = [
        'submission_open_at' => 'datetime',
        'submission_close_at' => 'datetime',
        'review_open_at' => 'datetime',
        'review_close_at' => 'datetime',
        'decision_open_at' => 'datetime',
        'decision_close_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function conference(): BelongsTo
    {
        return $this->belongsTo(Conference::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /**
     * The instant a phase opens: the start of the stored day, or null when the
     * phase is unbounded on that side (always open).
     *
     * Every phase check in the application reads its opening boundary through
     * here, so the convention lives in one place. Dates are entered as days in
     * the UI, which is why a boundary carries no meaningful time of day.
     */
    public function phaseOpensAt(Phase $phase): ?Carbon
    {
        // copy() because Carbon's start-of-day and end-of-day helpers mutate the
        // instance, which would corrupt the attribute on the loaded model.
        return $this->{$phase->openColumn()}?->copy()->startOfDay();
    }

    /**
     * The instant a phase closes: the end of the stored day, or null when the
     * phase never closes. A track whose CFP closes today therefore keeps accepting
     * submissions until midnight, which is why moving a phase up has to write
     * yesterday into a closing boundary, never today.
     */
    public function phaseClosesAt(Phase $phase): ?Carbon
    {
        return $this->{$phase->closeColumn()}?->copy()->endOfDay();
    }
}
