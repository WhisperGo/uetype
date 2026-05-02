<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Clan extends Model
{
    protected $fillable = [
        'name',
        'tag',
        'leader_id',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
