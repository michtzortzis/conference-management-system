<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubmissionCoAuthor extends Model
{
    protected $table = 'submission_coauthors';

    protected $fillable = [
        'submission_id',
        'user_id',
        'first_name',
        'last_name',
        'email',
        'invitation_sent_at',
        'invitation_accepted_at',
    ];

    protected $casts = [
        'invitation_sent_at' => 'datetime',
        'invitation_accepted_at' => 'datetime',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(Submission::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
