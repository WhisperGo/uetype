<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'google_id',
        'email',
        'username',
        'avatar',
        'highest_wpm',
        'total_xp',
        'is_admin',
        'preferences',
    ];

    protected $hidden = [
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'highest_wpm' => 'decimal:2',
            'is_admin' => 'boolean',
            'preferences' => 'array',
        ];
    }

    public function typingResults(): HasMany
    {
        return $this->hasMany(TypingResult::class);
    }

    public function matchParticipants(): HasMany
    {
        return $this->hasMany(MatchParticipant::class);
    }

    public function hostedMatches(): HasMany
    {
        return $this->hasMany(Matches::class, 'host_user_id');
    }

    public function sentFriendRequests(): HasMany
    {
        return $this->hasMany(Friendship::class, 'requester_id');
    }

    public function receivedFriendRequests(): HasMany
    {
        return $this->hasMany(Friendship::class, 'addressee_id');
    }

    public function achievements(): HasMany
    {
        return $this->hasMany(UserAchievement::class);
    }
}
