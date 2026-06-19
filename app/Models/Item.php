<?php

namespace App\Models;

use App\Models\UserItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    use SoftDeletes;

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
