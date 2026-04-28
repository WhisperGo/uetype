<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class Clan extends Model
{
    protected $fillable = [
        'name',
        'tag',
        'leader_id',
        'total_elo',
    ];

    public function users()
    {
        return $this->hasMany(User::class);
    }
}
