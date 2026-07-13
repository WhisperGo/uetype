<?php

namespace App\Models;

use App\Enums\FriendshipStatus;
use Binafy\LaravelUserMonitoring\Traits\Actionable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A directed friend relationship (requester -> addressee) with its status. */
class Friendship extends Model
{
    // Action monitoring: log create/update/delete pertemanan (binafy/laravel-user-monitoring).
    use Actionable;

    protected $fillable = [
        'requester_id',
        'addressee_id',
        'status',
    ];

    protected $casts = [
        'status' => FriendshipStatus::class,
    ];

    /** The user who sent the request. */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    /** The user who received the request. */
    public function addressee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'addressee_id');
    }
}
