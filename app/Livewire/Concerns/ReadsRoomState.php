<?php

namespace App\Livewire\Concerns;

use App\Models\Room;
use App\Models\RoomMember;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Read-model room: satu sumber baca untuk seluruh komponen multiplayer.
 *
 * Ini trait DASAR, bukan lapisan di atas yang lain: forgetRoomCache() dipanggil
 * dari sebelas method di semua kelompok, jadi apa pun yang mengubah room/room_members
 * bergantung padanya. Memindahkannya ke sini membuat ketergantungan itu eksplisit.
 */
trait ReadsRoomState
{
    /**
     * Room + member + host, sekali muat per request.
     *
     * #[Computed] wajib di sini: properti ini dibaca dari SEMBILAN tempat berbeda
     * (orderedMembers, isHost, allReady, suddenDeathActive, suddenDeathRemaining,
     * raceStartsAt, raceStartsInMs, myXpResult, captureResultSnapshot) plus view.
     * Getter gaya lama tak di-cache Livewire, jadi query dengan eager-load ini
     * dijalankan ulang tiap kali dibaca -- di komponen yang polling saat balapan.
     *
     * Cache-nya dibuang lewat forgetRoomCache() setiap kali komponen ini mengubah
     * room/room_members, supaya render setelah aksi tak memakai data basi.
     */
    #[Computed]
    public function roomData(): ?Room
    {
        if (
            ($this->step !== 'waiting' && $this->step !== 'racing')
            || empty($this->roomCode)
        ) {
            return null;
        }

        $room = Room::with(['members.user', 'host'])
            ->where('code', $this->roomCode)
            ->first();

        if (! $room) {
            $this->step = 'choose';

            return null;
        }

        return $room;
    }

    /** Buang cache room setelah room/room_members berubah di request ini. */
    private function forgetRoomCache(): void
    {
        unset($this->roomData, $this->leaderboardData);
    }

    /** Pembalap saja, terurut (host dulu): mengisi grid slot pemain. */
    public function getOrderedMembersProperty()
    {
        $room = $this->roomData;

        if (! $room) {
            return collect();
        }

        return $room->members
            ->where('role', RoomMember::ROLE_PLAYER)
            ->sortBy('id')
            ->sortByDesc(fn ($member) => $member->user_id === $room->host_id)
            ->values();
    }

    /** Penonton di room ini (host-penonton dulu), untuk daftar & badge. */
    public function getSpectatorsProperty()
    {
        $room = $this->roomData;

        if (! $room) {
            return collect();
        }

        return $room->members
            ->where('role', RoomMember::ROLE_SPECTATOR)
            ->sortByDesc(fn ($member) => $member->user_id === $room->host_id)
            ->sortBy('id')
            ->values();
    }

    public function getSpectatorCountProperty(): int
    {
        return $this->spectators->count();
    }

    /** True kalau user saat ini adalah penonton di room ini. */
    public function getIsSpectatorProperty(): bool
    {
        $room = $this->roomData;

        if (! $room) {
            return false;
        }

        return (bool) $room->members
            ->firstWhere('user_id', Auth::id())
            ?->isSpectator();
    }

    /**
     * Papan hasil balapan, terurut.
     *
     * with('user') itu wajib: query ini memuat ULANG members (bukan memakai yang sudah
     * di-eager-load di roomData), dan captureResultSnapshot() membaca $member->user
     * untuk tiap baris -- tanpa eager load itu satu query per pemain. Terjaring oleh
     * Model::preventLazyLoading(), bukan oleh mata.
     */
    #[Computed]
    public function leaderboardData()
    {
        if (! $this->roomData) {
            return collect();
        }

        // Hanya pembalap: podium & tabel hasil tak memuat penonton.
        return $this->roomData->members()
            ->where('role', RoomMember::ROLE_PLAYER)
            ->with('user')
            ->orderBy('wpm', 'desc')
            ->orderBy('progress_percent', 'desc')
            ->orderByRaw('finished_time_seconds IS NULL, finished_time_seconds ASC')
            ->get();
    }

    public function getStillInRoomUserIdsProperty(): array
    {
        $room = Room::where('code', $this->roomCode)->first();

        if (! $room) {
            return [];
        }

        return RoomMember::where('room_id', $room->id)->pluck('user_id')->all();
    }

    /**
     * Data EXP untuk panel hasil match: earned (room_members.xp_earned) + level
     * (levelData() user terkini). Null-safe untuk guest / sebelum EXP diberikan.
     *
     * @return array{earned:int, level:array}|null
     */
    public function getMyXpResultProperty(): ?array
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        $earned = 0;
        if ($this->roomData) {
            $me = $this->roomData->members->firstWhere('user_id', $user->id);
            $earned = (int) ($me->xp_earned ?? 0);
        }

        return [
            'earned' => $earned,
            'level' => $user->levelData(),
        ];
    }

    public function getIsHostProperty(): bool
    {
        $room = $this->roomData;

        return $room ? $room->host_id === Auth::id() : false;
    }

    public function getAllReadyProperty(): bool
    {
        $room = $this->roomData;

        if (! $room) {
            return false;
        }

        // Hanya pembalap non-host yang perlu ready. Kalau host jadi penonton, ia bukan
        // pembalap sehingga semua pembalap ikut dihitung -- semuanya wajib ready.
        $participants = $room->members
            ->where('role', RoomMember::ROLE_PLAYER)
            ->where('user_id', '!=', $room->host_id);

        return $participants->count() > 0 && $participants->where('is_ready', false)->count() === 0;
    }

    /** True kalau sudden death aktif (minimal satu player finish, room masih racing). */
    public function getSuddenDeathActiveProperty(): bool
    {
        $room = $this->roomData;

        return (bool) ($room && $room->status === 'racing' && $room->countdown_started_at);
    }

    /** Sisa waktu sudden death dalam detik mundur (15 -> 0), bukan elapsed. */
    public function getSuddenDeathRemainingProperty(): int
    {
        $room = $this->roomData;

        if (! $room || ! $room->countdown_started_at) {
            return self::SUDDEN_DEATH_SECONDS;
        }

        $elapsed = now()->diffInSeconds($room->countdown_started_at, true);

        return max(0, self::SUDDEN_DEATH_SECONDS - (int) floor($elapsed));
    }

    /** Waktu absolut (ISO string) kapan race resmi mulai, untuk countdown 3-2-1 sinkron. */
    public function getRaceStartsAtProperty(): ?string
    {
        $room = $this->roomData;

        return $room && $room->race_starts_at
            ? $room->race_starts_at->toIso8601String()
            : null;
    }

    /**
     * Sisa milidetik menuju start, dihitung SERVER saat render.
     *
     * Ini sengaja bukan "jam server" absolut: membandingkan jam server dengan
     * Date.now() klien menghitung latensi jaringan sebagai selisih jam, dan
     * toIso8601String() memotong milidetik (galat sampai 1 detik). Dengan durasi
     * relatif, jam klien & zona waktu tak lagi relevan -- klien cukup menghitung
     * mundur sebanyak ini sejak halaman diterima.
     *
     * null kalau race belum dijadwalkan.
     */
    public function getRaceStartsInMsProperty(): ?int
    {
        $room = $this->roomData;

        if (! $room || ! $room->race_starts_at) {
            return null;
        }

        // Boleh negatif -> race sudah lewat titik mulai (mis. pemain refresh di
        // tengah balapan); klien langsung masuk race tanpa countdown.
        return (int) round((float) now()->diffInMilliseconds($room->race_starts_at, false));
    }
}
