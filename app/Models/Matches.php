<?php

namespace App\Models;

use App\Enums\MatchStatus;
use App\Enums\MatchType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    public function host(): BelongsTo
    {
        return $this->belongsTo(User::class, 'host_user_id');
    }

    public function text(): BelongsTo
    {
        return $this->belongsTo(Text::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(MatchParticipant::class, 'match_id');
    }
}
