<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Conference extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'title',
        'description',
        'start_date',
        'end_date',
        'double_blind_review',
        'coi_auto_detect',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
        'double_blind_review' => 'boolean',
        'coi_auto_detect' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    public function fields(): BelongsToMany
    {
        return $this->belongsToMany(Field::class, 'conference_fields');
    }

    public function tracks(): HasMany
    {
        return $this->hasMany(Track::class);
    }

    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }
}
