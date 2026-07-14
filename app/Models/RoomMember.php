<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A player in a race room: live race state, final placement, and XP earned. */
class RoomMember extends Model
{
    /**
     * Penanda DNF (menyerah / kehabisan waktu sudden death) di kolom
     * finished_time_seconds. BUKAN durasi sungguhan -- cuma nilai besar supaya
     * pemain DNF selalu terurut di bawah semua penyelesai sah.
     *
     * Sentinel ini transient: rooms/room_members dihapus begitu semua pemain
     * keluar. Ia TIDAK BOLEH ikut ke multiplayer_match_history (riwayat permanen),
     * karena di sana kolomnya bermakna "durasi tempuh" dan 999 akan mencemari
     * statistik apa pun yang menghitung rata-rata waktu finish.
     */
    public const DNF_SENTINEL_SECONDS = 999;

    public const ROLE_PLAYER = 'player';

    public const ROLE_SPECTATOR = 'spectator';

    protected $fillable = [
        'room_id', 'user_id', 'role', 'is_ready', 'progress_percent',
        'wpm', 'accuracy', 'finished_time_seconds', 'place', 'xp_earned',
        'result_recorded',
    ];

    protected $casts = [
        'result_recorded' => 'boolean',
    ];

    /** Pemain ini tidak menyelesaikan balapan (menyerah / kehabisan waktu). */
    public function isDnf(): bool
    {
        return (int) $this->finished_time_seconds === self::DNF_SENTINEL_SECONDS;
    }

    /** Durasi tempuh SUNGGUHAN, atau null kalau DNF (tak ada durasi yang bermakna). */
    public function realFinishedSeconds(): ?int
    {
        return $this->isDnf() ? null : $this->finished_time_seconds;
    }

    /** Penonton: menonton live, tak masuk klasemen/place/XP. */
    public function isSpectator(): bool
    {
        return $this->role === self::ROLE_SPECTATOR;
    }

    /** Pembalap: ikut race, klasemen, place, dan XP. */
    public function isPlayer(): bool
    {
        return ! $this->isSpectator();
    }

    /** The user this membership belongs to. */
    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
