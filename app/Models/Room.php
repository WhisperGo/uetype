<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Room extends Model
{
    protected $fillable = ['code', 'host_id', 'status', 'text_to_type', 'countdown_started_at'];

    public function host()
    {
        // Ganti $table menjadi $this
        return $this->belongsTo(User::class, 'host_id');
    }

    public function members()
    {
        return $this->hasMany(RoomMember::class, 'room_id');
    }
}