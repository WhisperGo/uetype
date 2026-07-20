<?php

namespace App\Livewire\Concerns;

use App\Models\MultiplayerMatchHistory;
use App\Models\Room;
use App\Models\RoomMember;
use App\Services\AntiCheatService;
use Illuminate\Support\Facades\DB;

/**
 * Penutupan balapan: menetapkan peringkat & durasi final, memvalidasinya lewat
 * anti-cheat, dan membekukan papan hasil.
 *
 * Diekstrak karena ini SATU-SATUNYA kelompok di MultiplayerLobby yang batasnya
 * benar-benar bersih: ia hanya dipanggil MASUK (dari updateRaceProgress, giveUp,
 * dan checkSuddenDeath) dan tak memanggil balik apa pun selain read-model.
 *
 * Siklus-hidup-room dan siklus-balapan sengaja TIDAK dipisahkan: keduanya saling
 * bertaut (roomUpdated/render memanggil resetToChoose, playAgain me-reset state
 * sudden death), jadi memecahnya hanya menghasilkan trait yang saling-use tanpa
 * menambah kejelasan.
 */
trait FinalizesRace
{
    /**
     * Seluruhnya dalam SATU transaksi: satu balapan menulis place & xp_earned untuk
     * tiap pemain, total_xp tiap user, dan satu baris riwayat permanen per pemain.
     * Gagal di tengah tanpa transaksi meninggalkan balapan separuh final -- dan
     * karena guard idempoten `xp_earned IS NULL` menganggap pemain yang terlanjur
     * diproses sudah selesai, pemanggilan ulang tak akan memperbaikinya.
     *
     * Guard idempoten TETAP diperlukan: transaksi melindungi dari tulis separuh
     * jadi, guard melindungi dari pemanggilan ganda (fast-path "semua finish" dan
     * checkSuddenDeath bisa sama-sama sampai ke sini). Beda peran, bukan duplikasi.
     */
    public function finalizeRace(string $roomId): void
    {
        DB::transaction(fn () => $this->writeFinalStandings($roomId));

        // place/xp/result_recorded baru saja berubah -> snapshot & view harus membaca
        // ulang. Di LUAR transaksi: ini membuang cache di memori, bukan menulis DB.
        $this->forgetRoomCache();
    }

    private function writeFinalStandings(string $roomId): void
    {
        $room = Room::find($roomId);
        $textLength = $room ? mb_strlen($room->text_to_type) : 0;

        // Hanya pembalap yang difinalisasi: penonton tak punya place/XP dan tak boleh
        // mencemari urutan podium maupun jumlah pemain di riwayat.
        //
        // "Belum finis" HARUS jadi kunci urut pertama. MySQL menaruh NULL paling awal
        // pada ASC, jadi dengan finished_time_seconds sebagai kunci pertama, pemain
        // yang belum selesai akan mendarat di atas penyelesai yang sah -- juara 1 untuk
        // orang yang tak menyelesaikan balapan. Ketiga pemanggil finalizeRace() saat ini
        // menjamin tak ada NULL yang sampai ke sini (mereka menunggu semua finis atau
        // menyetel sentinel DNF lebih dulu), jadi ini kerapuhan, bukan bug hidup --
        // tapi kerapuhan yang harganya satu baris.
        $members = RoomMember::with('user')
            ->where('room_id', $roomId)
            ->where('role', RoomMember::ROLE_PLAYER)
            ->orderByRaw('finished_time_seconds IS NULL')
            ->orderBy('finished_time_seconds', 'asc')
            ->orderBy('progress_percent', 'desc')
            ->get();

        foreach ($members as $index => $member) {
            $place = $index + 1;
            $updateData = ['place' => $place];

            // EXP sekali per pemain: xp_earned null = belum diberi (aman dari double-award
            // lewat fast-path "semua finish" maupun checkSuddenDeath). rooms/room_members
            // dihapus begitu semua pemain keluar, jadi baris riwayat permanen ditulis di
            // sini juga -- satu-satunya titik semua kolom final (place, wpm, akurasi, xp)
            // sudah settled sebelum room bisa lenyap.
            if (is_null($member->xp_earned) && $member->user) {
                // correctChars diturunkan dari progress% x panjang teks (room_members tak
                // menyimpan jumlah karakter benar), lalu pakai rumus sama dengan mode solo.
                $progress = max(0, min(100, (int) $member->progress_percent));
                $correctChars = (int) round(($progress / 100) * $textLength);

                // Gerbang validitas sama seperti mode solo: hasil yang tak masuk akal
                // (WPM mustahil, karakter tak konsisten, durasi mustahil) DITOLAK -- tak
                // ditulis ke riwayat & tak dapat EXP, supaya average WPM pemain tak rusak.
                $isValid = $this->isValidRaceResult($member, $correctChars);
                $updateData['result_recorded'] = $isValid;

                if ($isValid) {
                    $xp = $member->user->addExp($correctChars, (float) $member->accuracy);
                    $updateData['xp_earned'] = $xp;

                    MultiplayerMatchHistory::create([
                        'user_id' => $member->user_id,
                        'room_code' => $room?->code ?? '',
                        'place' => $place,
                        'player_count' => $members->count(),
                        'wpm' => (int) $member->wpm,
                        'accuracy' => (float) $member->accuracy,
                        // Sentinel DNF (999) TAK BOLEH lewat ke riwayat permanen: di sini
                        // kolomnya bermakna "durasi tempuh", dan 999 akan dibaca sebagai
                        // durasi sungguhan oleh statistik apa pun yang merata-ratakannya.
                        'finished_time_seconds' => $member->realFinishedSeconds(),
                        'dnf' => $member->isDnf(),
                        'xp_earned' => $xp,
                    ]);
                } else {
                    // Tetap tandai xp_earned (0) agar guard idempoten di atas tak
                    // memproses ulang pemain ini pada pemanggilan finalizeRace berikutnya.
                    $updateData['xp_earned'] = 0;
                }
            }

            $member->update($updateData);
        }
    }

    /**
     * Nyalakan timer sudden death kalau belum menyala. Mengembalikan true HANYA pada
     * pemanggilan yang benar-benar menyalakannya (pemanggil itulah yang broadcast).
     *
     * Syaratnya cuma satu -- "timer belum menyala" -- dan sengaja TIDAK bertanya
     * "apakah saya pemain pertama yang finish". Pertanyaan kedua itu dulu ikut jadi
     * syarat, dan berbahaya: hitungan finish dibaca SEBELUM status finish pemain ini
     * ditulis, jadi kalau sudah ada yang tercatat finish tapi timer belum sempat
     * menyala, pemain berikutnya gagal syarat "pertama" -> timer tak pernah menyala
     * -> checkSuddenDeath() selalu return -> pemain sisa menggantung selamanya.
     *
     * Update bersyarat `whereNull(...)` membuatnya atomik: dari dua request paralel,
     * hanya satu yang dapat affected-rows = 1, jadi timer tak bisa di-reset oleh
     * pemain kedua. Pola yang sama dipakai TypingEngine::attachToWarClaim().
     */
    private function startSuddenDeathIfNeeded(Room $room): bool
    {
        $claimed = Room::where('id', $room->id)
            ->whereNull('countdown_started_at')
            ->update(['countdown_started_at' => now()]);

        if (! $claimed) {
            return false;
        }

        // Muat ulang supaya pemanggil bisa membaca countdown_started_at yang baru
        // (dipakai untuk menghitung deadline yang di-broadcast).
        $room->refresh();

        return true;
    }

    /**
     * Server-side validity gate for a finished race result, reusing AntiCheatService.
     * Only genuine cheat signals reject (impossible WPM / inconsistent chars) --
     * NOT low throughput/short duration, which are normal for a DNF or slow finish
     * (those stay recorded, matching the "anti-cheat only" rule). totalChars ==
     * correctChars because race progress only advances on correct characters.
     */
    private function isValidRaceResult(RoomMember $member, int $correctChars): bool
    {
        $duration = (float) ($member->finished_time_seconds ?? 0);

        $antiCheat = app(AntiCheatService::class);
        $reasons = $antiCheat->check($correctChars, $correctChars, $duration)['reasons'];

        // Daftar sinyal "mustahil" hidup di AntiCheatService, bukan disalin ke sini:
        // satu definisi kecurangan, dipakai race maupun solo.
        return ! $antiCheat->isCheating($reasons);
    }

    private function captureResultSnapshot(): void
    {
        if (! empty($this->resultSnapshot) || ! $this->roomData) {
            return;
        }

        $this->resultSnapshot = $this->leaderboardData->map(fn ($member) => [
            'user_id' => $member->user_id,
            'username' => $member->user->username,
            'avatar' => $member->user->avatar,
            'wpm' => (int) $member->wpm,
            'accuracy' => $member->accuracy,
            'finished_time_seconds' => $member->finished_time_seconds,
            'place' => $member->place,
            // false = ditolak anti-cheat (tak masuk statistik); null = belum difinalisasi.
            'result_recorded' => $member->result_recorded,
        ])->values()->all();
    }
}
