<?php

namespace App\Livewire\Concerns;

use App\Models\Room;
use App\Models\RoomMember;

/**
 * Perpindahan pemain antar-room: keluar dari yang lama, merawat apa yang
 * ditinggalkan.
 *
 * Trait ini MENULIS, dan karena itu sengaja terpisah dari ReadsRoomState yang
 * murni read-model -- keduanya digabung akan mengaburkan mana yang aman dipanggil
 * di dalam render.
 *
 * Alasannya ada: aturan "satu pemain, paling banyak satu room" dulu hanya
 * dihormati leaveRoom(). createRoom() menghapus baris room_members milik user
 * tapi tak pernah memeriksa apakah room lamanya jadi kosong -- baris `rooms`
 * yatim menumpuk selamanya. joinRoom() lebih buruk: ia tak membuang keanggotaan
 * lama sama sekali, jadi pemain bisa terdaftar di dua room, mengunci slot di room
 * yang sudah ditinggalkan dan menyisakan host yang tak ada orangnya.
 *
 * Sekarang ketiga jalur memakai satu pintu yang sama.
 */
trait ManagesRoomMembership
{
    /**
     * Keluarkan $userId dari room yang sedang ia diami, lalu rawat setiap room
     * yang ditinggalkan: kosong -> dihapus, masih berisi -> host dialihkan bila
     * yang pergi adalah host-nya.
     *
     * $exceptRoomId dikecualikan. Ini penting untuk joinRoom(): kalau pemain
     * memasukkan kode room yang SUDAH ia diami, tanpa pengecualian ini ia akan
     * "keluar" lebih dulu -- dan kalau ia host, room-nya berpindah tangan lalu ia
     * masuk lagi sebagai anggota biasa. Bergabung ke room sendiri tak boleh
     * membuat siapa pun kehilangan status host.
     *
     * Dipanggil dari dalam transaksi (lihat createRoom/joinRoom): antara
     * penghapusan keanggotaan lama dan pembuatan yang baru tak boleh ada jendela
     * di mana pemain tak ada di room mana pun -- atau, lebih buruk, ada di dua.
     */
    private function departCurrentRooms(int $userId, ?int $exceptRoomId = null): void
    {
        $query = RoomMember::where('user_id', $userId)
            ->when($exceptRoomId, fn ($q) => $q->where('room_id', '!=', $exceptRoomId));

        $roomIds = (clone $query)->pluck('room_id')->unique();

        if ($roomIds->isEmpty()) {
            return;
        }

        $query->delete();

        foreach (Room::whereIn('id', $roomIds)->get() as $room) {
            $this->settleAbandonedRoom($room, $userId);
        }
    }

    /**
     * Room yang baru saja ditinggal $leavingUserId: buang kalau tak bersisa
     * anggota, kalau tidak pastikan ia masih punya host yang benar-benar ada.
     */
    private function settleAbandonedRoom(Room $room, int $leavingUserId): void
    {
        // lockForUpdate: dua pemain terakhir yang keluar bersamaan tak boleh
        // sama-sama membaca "masih ada sisa" lalu tak seorang pun menghapus room.
        $remaining = RoomMember::where('room_id', $room->id)->lockForUpdate()->count();

        if ($remaining === 0) {
            $room->delete();

            return;
        }

        $this->reassignHostIfNeeded($room, $leavingUserId);
    }
}
