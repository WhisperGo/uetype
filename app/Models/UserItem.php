<?php

namespace App\Models;

use App\Models\User;
use App\Models\Item;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserItem extends Model
{
    public const UPDATED_AT = null;
    public const CREATED_AT = 'purchased_at';

    protected $fillable = [
        'user_id',
        'item_id',
        'is_equipped',
    ];

    protected $casts = [
        'is_equipped' => 'boolean',
        'purchased_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }
}
