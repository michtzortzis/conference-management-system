<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Affiliation extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'type',
        'country',
    ];

    public function users()
    {
        return $this->belongsToMany(User::class)->withPivot(['department', 'position'])->withTimestamps();
    }
}
