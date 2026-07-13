<?php

namespace App\Models;

use App\Enums\MatchStatus;
use App\Enums\MatchType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A typing match (1v1 or group): host, text, timing, and participants. */
class Matches extends Model
{
    protected $fillable = [
        'room_code',
        'host_user_id',
        'text_id',
        'generated_text',
        'match_type',
        'status',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'match_type' => MatchType::class,
        'status' => MatchStatus::class,
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    /** The user who created the match. */
    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    /** The text raced on (null when the text was generated). */
    public function text(): BelongsTo
    {
        return $this->belongsTo(Text::class);
    }

    /** All players in the match. */
    public function participants(): HasMany
    {
        return $this->hasMany(MatchParticipant::class, 'match_id');
    }
}
