<?php

namespace App\Models;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A user's membership in a clan: their role (leader/member) and status
 * (pending join request vs. active member).
 */
class ClanMember extends Model
{
    protected $fillable = [
        'clan_id',
        'user_id',
        'role',
        'status',
    ];

    protected $casts = [
        'role' => ClanRole::class,
        'status' => ClanMemberStatus::class,
    ];

    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
