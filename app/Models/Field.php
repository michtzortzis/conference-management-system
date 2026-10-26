<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Field extends Model
{
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int,string>
     */
    protected $fillable = [
        'name',
        'description',
    ];

    /**
     * The conferences this field applies to.
     */
    public function conferences(): BelongsToMany
    {
        return $this->belongsToMany(Conference::class, 'conference_fields');
    }
}
