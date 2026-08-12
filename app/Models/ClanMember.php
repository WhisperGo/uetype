<?php

namespace App\Models;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A user's clan membership: their role and status. */
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

    /** The clan being joined. */
    public function clan(): BelongsTo
    {
        return $this->belongsTo(Clan::class);
    }

    /** The member user. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
