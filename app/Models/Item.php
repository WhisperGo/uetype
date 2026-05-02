<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Models\UserItem;

class Item extends Model
{
    protected $fillable = [
        'name',
        'type',
        'price',
    ];

    public function userItems(): HasMany
    {
        return $this->hasMany(UserItem::class);
    }
}
