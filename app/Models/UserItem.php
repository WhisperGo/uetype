<?php

namespace App\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use App\Models\Item;

class UserItem extends Model
{
    protected $fillable = [
        'user_id',
        'item_id',
        'is_equipped',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function item(): BelongsToMany
    {
        return $this->belongsToMany(Item::class, 'user_items')
                ->withPivot('is_equipped')
                ->withTimestamps();
    }
}
