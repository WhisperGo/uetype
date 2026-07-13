<?php

namespace App\Models;

use Binafy\LaravelUserMonitoring\Traits\Actionable;
use Illuminate\Database\Eloquent\Model;

/** A race lobby: shared text, status, and countdown/start timestamps. */
class Room extends Model
{
    // Action monitoring: log create/update/delete room (binafy/laravel-user-monitoring).
    use Actionable;

    protected $fillable = ['code', 'host_id', 'status', 'text_to_type', 'countdown_started_at', 'race_starts_at'];

    // Kolom waktu WAJIB di-cast ke datetime supaya selalu jadi objek Carbon, bukan
    // string. Tanpa ini, setelah refresh()/query kolomnya berupa string sehingga
    // ->copy()/->diffInSeconds() gagal ("Call to a member function copy() on string").
    protected $casts = [
        'countdown_started_at' => 'datetime',
        'race_starts_at' => 'datetime',
    ];

    /** The user who created and owns the room. */
    public function host()
    {
        return $this->belongsTo(User::class, 'host_id');
    }

    /** All players currently in the room. */
    public function members()
    {
        return $this->hasMany(RoomMember::class, 'room_id');
    }
}
