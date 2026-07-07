<?php

namespace App\Livewire;

use App\Events\RaceProgressUpdated;
use App\Events\RoomUpdated;
use App\Events\SuddenDeathTriggered;
use App\Models\Room;
use App\Models\RoomMember;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

class MultiplayerLobby extends Component
{
    public string $step = 'choose';

    public string $roomCode = '';

    public array $joinCodeInput = ['', '', '', '', '', ''];

    public string $typedText = '';

    public bool $showResultModal = false;

    // Durasi sudden death dalam detik. Diekstrak jadi konstanta supaya
    // checkSuddenDeath() dan getSuddenDeathRemainingProperty() selalu
    // pakai angka yang sama persis (sebelumnya '15' ditulis manual di
    // checkSuddenDeath() saja, sehingga view harus menebak/re-implement
    // sendiri arah hitungannya — ini sumber bug "maju" tadi).
    private const SUDDEN_DEATH_SECONDS = 15;

    // Durasi hitung mundur awal race (overlay "3, 2, 1, GO!") dalam detik. Server
    // menetapkan race_starts_at = now() + durasi ini agar semua klien sinkron.
    private const COUNTDOWN_SECONDS = 3;

    public function createRoom(): void
    {
        $user = Auth::user();

        RoomMember::where('user_id', $user->id)->delete();

        $code = strtoupper(Str::random(6));

        // Kunci kalimat acak yang sama ini ke dalam database ruangan
        $room = Room::create([
            'code' => $code,
            'host_id' => $user->id,
            'status' => 'waiting',
            'text_to_type' => $this->generateRaceText(),
        ]);

        RoomMember::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'is_ready' => true,
            'progress_percent' => 0,
            'wpm' => 0,
            'accuracy' => 100,
            'finished_time_seconds' => null,
        ]);

        $this->roomCode = $code;
        $this->step = 'waiting';

        $this->dispatch('subscribe-room', room: $code);
    }

    /**
     * 🎲 ARSITEKTUR REUSE: Merakit kalimat acak dari Wordlist JSON (mengikuti
     * jalur Solo Mode). Diekstrak dari createRoom() supaya bisa dipakai ulang
     * di playAgain() — sebelumnya playAgain() tidak generate teks baru sama
     * sekali, jadi teks race ke-2 dst selalu identik dengan race pertama.
     */
    private function generateRaceText(): string
    {
        $textToType = "And i know we were perfect but i never felt this way for no one and i just can't imagine how you could be so okay now that I gone guess you did mean what you wrote in that song about me cause you said forever now i drive alone past";

        $path = base_path('database/data/indonesian.json');

        if (File::exists($path)) {
            $jsonString = File::get($path);
            $data = json_decode($jsonString, true);

            if (is_array($data) && isset($data['words']) && is_array($data['words'])) {
                $wordsArray = $data['words'];

                // Acak seluruh isi wordlist
                shuffle($wordsArray);

                // Ambil batas aman kata untuk balapan bersama (misal: 45 hingga 50 kata)
                $limit = 45;
                $selectedWords = [];

                while (count($selectedWords) < $limit) {
                    shuffle($wordsArray);
                    $needed = $limit - count($selectedWords);
                    $selectedWords = array_merge($selectedWords, array_slice($wordsArray, 0, $needed));
                }

                // Gabungkan kumpulan kata acak menjadi satu paragraf balapan utuh
                $textToType = implode(' ', $selectedWords);
            }
        }

        return $textToType;
    }

    public function joinRoom(): void
    {
        $code = strtoupper(implode('', $this->joinCodeInput));

        if (strlen($code) !== 6) {
            session()->flash('error', __('multiplayer.error_code_length'));

            return;
        }

        $room = Room::where('code', $code)->where('status', 'waiting')->first();

        if (! $room) {
            session()->flash('error', __('multiplayer.error_room_not_found'));

            return;
        }

        if ($room->members()->count() >= 5) {
            session()->flash('error', __('multiplayer.error_room_full'));

            return;
        }

        RoomMember::updateOrCreate(
            ['room_id' => $room->id, 'user_id' => Auth::id()],
            [
                'is_ready' => false,
                'progress_percent' => 0,
                'wpm' => 0,
                'accuracy' => 100,
                'finished_time_seconds' => null,
            ]
        );

        $this->roomCode = $code;
        $this->step = 'waiting';

        $this->dispatch('subscribe-room', room: $code);

        broadcast(new RoomUpdated($code))->toOthers();
    }

    #[On('room-updated')]
    public function roomUpdated()
    {
        // logger('EVENT MASUK');

        $room = Room::where('code', $this->roomCode)->first();

        if (! $room) {
            return;
        }

        if ($room->status === 'racing' && $this->step !== 'racing') {
            $this->step = 'racing';
            $this->showResultModal = false;
            $this->typedText = '';
        }

        if ($room->status === 'finished') {
            $this->showResultModal = true;
        }

        if ($room->status === 'waiting' && $this->step === 'racing') {
            $this->step = 'waiting';
            $this->typedText = '';
            $this->showResultModal = false;
        }
    }

    public function finalizeRace(string $roomId): void
    {
        $room = Room::find($roomId);
        $textLength = $room ? mb_strlen($room->text_to_type) : 0;

        $members = RoomMember::with('user')
            ->where('room_id', $roomId)
            ->orderBy('wpm', 'desc')
            ->orderBy('progress_percent', 'desc')
            ->orderByRaw('finished_time_seconds IS NULL, finished_time_seconds ASC')
            ->get();

        foreach ($members as $index => $member) {
            $updateData = ['place' => $index + 1];

            // Beri EXP SEKALI per pemain. xp_earned yang masih null = belum pernah
            // diberi -> aman dari double-award (finalizeRace bisa terpanggil dari
            // fast-path "semua finish" maupun dari checkSuddenDeath).
            if (is_null($member->xp_earned) && $member->user) {
                // room_members tidak menyimpan jumlah karakter benar, jadi diturunkan
                // dari progress: correctChars = progress% × panjang teks. Ini memberi
                // basis VOLUME yang setara dengan mode solo, dipakai rumus yang SAMA
                // (User::addExp) supaya EXP multiplayer & solo konsisten.
                $progress = max(0, min(100, (int) $member->progress_percent));
                $correctChars = (int) round(($progress / 100) * $textLength);

                $xp = $member->user->addExp($correctChars, (float) $member->accuracy);
                $updateData['xp_earned'] = $xp;
            }

            $member->update($updateData);
        }
    }

    public function toggleReady(): void
    {
        $room = Room::where('code', $this->roomCode)->first();

        if (! $room) {
            return;
        }

        $member = RoomMember::where('room_id', $room->id)->where('user_id', Auth::id())->first();

        if ($member && $room->host_id !== Auth::id()) {
            $member->update([
                'is_ready' => ! $member->is_ready,
            ]);

            broadcast(new RoomUpdated($this->roomCode))->toOthers();
        }
    }

    public function leaveRoom(): void
    {
        $room = Room::where('code', $this->roomCode)->first();

        if ($room) {
            RoomMember::where('room_id', $room->id)->where('user_id', Auth::id())->delete();

            if ($room->host_id === Auth::id()) {
                $room->delete();
            }

            broadcast(new RoomUpdated($this->roomCode))->toOthers();
        }

        $this->roomCode = '';
        $this->joinCodeInput = ['', '', '', '', '', ''];
        $this->step = 'choose';
        $this->typedText = '';
        $this->showResultModal = false;

        $this->dispatch('leave-room');
    }

    public function updateRaceProgress(int $progressPercent, int $liveWpm, int $accuracy = 100): void
    {
        $room = Room::where('code', $this->roomCode)->first();
        if (! $room || $room->status !== 'racing') {
            return;
        }

        $member = RoomMember::where('room_id', $room->id)->where('user_id', Auth::id())->first();

        // FIX bug laten: guard sebelumnya cek `finished_at` yang TIDAK ADA di skema
        // (kolomnya `finished_time_seconds`) -> selalu null -> guard mati -> pemain
        // yang sudah finish tetap mem-broadcast progress. Sekarang cek kolom benar.
        if (! $member || ! is_null($member->finished_time_seconds)) {
            return;
        }

        $progressPercent = min(100, max(0, $progressPercent));
        $accuracy = min(100, max(0, $accuracy));

        $updateData = [
            'progress_percent' => $progressPercent,
            'wpm' => $liveWpm,
            'accuracy' => $accuracy,
        ];

        $justFinished = false;
        $suddenDeathJustStarted = false;

        if ($progressPercent >= 100) {
            $justFinished = true;

            // Durasi tempuh ASLI = sekarang - kapan race benar-benar mulai
            // (race_starts_at, titik countdown 3-2-1 selesai). BUG lama memakai
            // $room->updated_at yang berubah tiap update baris room, jadi angkanya
            // acak & kecil (2s/5s), bukan lama mengetik sebenarnya. Fallback ke
            // updated_at hanya kalau race_starts_at entah kenapa kosong (jaga-jaga).
            // absolute: true -> cegah hasil negatif (Carbon 3 default-nya signed diff).
            $raceStart = $room->race_starts_at ?? $room->updated_at;
            $updateData['finished_time_seconds'] = (int) round($raceStart->diffInSeconds(now(), true));

            $alreadyFinishedCount = RoomMember::where('room_id', $room->id)
                ->whereNotNull('finished_time_seconds')
                ->count();

            $updateData['place'] = $alreadyFinishedCount + 1;

            if ($alreadyFinishedCount === 0 && ! $room->countdown_started_at) {
                $room->update([
                    'countdown_started_at' => now(),
                ]);
                $room->refresh();
                $suddenDeathJustStarted = true;
            }
            $this->showResultModal = true;
        }

        $member->update($updateData);

        // GERAKAN MASKOT LAWAN = payload langsung lewat WebSocket (channel race.{code}).
        // Ini mengganti pola lama "broadcast ping RoomUpdated -> tiap client re-render
        // Livewire + query DB". Sekarang klien lain cukup baca payload ini dan geser
        // maskot di Alpine store, tanpa round-trip server. Inilah kunci zero-delay.
        broadcast(new RaceProgressUpdated($this->roomCode, Auth::id(), [
            'progress_percent' => $progressPercent,
            'wpm' => $liveWpm,
            'accuracy' => $accuracy,
            'finished' => $justFinished,
        ]))->toOthers();

        // Saat pemain PERTAMA finish -> sudden death mulai. Kirim timestamp akhir yang
        // sama ke semua klien supaya hitung mundur mereka tersinkron (bukan tiap klien
        // menebak sendiri). Server tetap gerbang final via checkSuddenDeath().
        if ($suddenDeathJustStarted) {
            broadcast(new SuddenDeathTriggered(
                $this->roomCode,
                $room->countdown_started_at->copy()->addSeconds(self::SUDDEN_DEATH_SECONDS)->toIso8601String(),
            ));
        }

        // Lifecycle (bukan sekadar gerakan): saat ada yang finish, kondisi room berubah
        // (badge FINISHED, modal hasil, place). Ini butuh re-render -> pakai RoomUpdated.
        if ($justFinished) {
            // Fast-path: kalau SEMUA peserta sudah finish, tutup room sekarang juga
            // tanpa menunggu timeout sudden death 15 detik.
            $unfinished = RoomMember::where('room_id', $room->id)
                ->whereNull('finished_time_seconds')
                ->count();

            if ($unfinished === 0) {
                $room->update(['status' => 'finished']);
                $this->finalizeRace($room->id);
            }

            broadcast(new RoomUpdated($this->roomCode));
        }
    }

    public function checkSuddenDeath(): void
    {
        if (! $this->roomCode || $this->step !== 'racing') {
            return;
        }

        $room = Room::where('code', $this->roomCode)->first();
        if (! $room || ! $room->countdown_started_at) {
            return;
        }

        // Hitung detik yang SUDAH berlalu sejak sudden death dimulai (maju, 0 → 15).
        // Ini dipakai HANYA sebagai syarat auto-finish — bukan untuk ditampilkan
        // langsung ke player. Untuk tampilan mundur (15 → 0), pakai
        // getSuddenDeathRemainingProperty() di bawah.
        $secondsPassed = now()->diffInSeconds($room->countdown_started_at, true);

        // Jika sudah melewati batas waktu, paksa kunci game menjadi 'finished'
        if ($secondsPassed >= self::SUDDEN_DEATH_SECONDS) {
            $room->update(['status' => 'finished']);

            // Berikan peringkat default ke pemain yang belum selesai berdasarkan progress tertinggi
            RoomMember::where('room_id', $room->id)
                ->whereNull('finished_time_seconds')
                ->update([
                    'finished_time_seconds' => 999, // Penanda DNF (Did Not Finish)
                ]);

            $this->finalizeRace($room->id);
            $this->showResultModal = true;

            // Perintahkan Alpine di klien ini untuk mengunci input SEKARANG JUGA.
            // Dipanggil sekali oleh klien saat hitung-mundur sinkronnya menyentuh 0
            // (bukan lagi via poll 1 detik). Guard `>= SUDDEN_DEATH_SECONDS` di atas
            // membuat method ini idempoten meski dipicu beberapa klien sekaligus.
            $this->dispatch('force-finish');

            // Broadcast ke SEMUA (bukan toOthers): klien yang memicu ini juga perlu
            // menerima status 'finished' final + peringkat DNF yang baru dikunci.
            broadcast(new RoomUpdated($this->roomCode));
        }
    }

    public function playAgain(): void
    {
        $room = Room::where('code', $this->roomCode)->first();
        if ($room && $room->host_id === Auth::id()) {
            $room->update([
                'status' => 'waiting',
                // FIX bug 1: countdown_started_at WAJIB direset ke null di sini.
                // Sebelumnya kolom ini tidak disentuh, jadi timestamp sudden death
                // dari race sebelumnya masih nyangkut. Begitu race baru dimulai dan
                // status kembali 'racing', checkSuddenDeath() langsung melihat
                // elapsed time yang sudah jauh lebih dari 15 detik (dihitung dari
                // race lama) -> auto-finish dalam ~1 detik -> leaderboard "langsung"
                // muncul lagi.
                'countdown_started_at' => null,
                // FIX bug 2: generate teks balapan baru, sama seperti createRoom().
                // Sebelumnya text_to_type tidak pernah diperbarui di sini, jadi
                // race ke-2 dst memakai teks yang identik dengan race pertama.
                'text_to_type' => $this->generateRaceText(),
            ]);
            RoomMember::where('room_id', $room->id)->update([
                'is_ready' => false,
                'progress_percent' => 0,
                'wpm' => 0,
                'accuracy' => 100,
                'finished_time_seconds' => null,
                'place' => null,
                // Reset penanda EXP -> race berikutnya bisa memberi EXP lagi (sekali).
                'xp_earned' => null,
            ]);

            $this->step = 'waiting';
            $this->showResultModal = false;
            $this->typedText = '';

            broadcast(new RoomUpdated($this->roomCode))->toOthers();
        }
    }

    public function getRoomDataProperty(): ?Room
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

    public function getLeaderboardDataProperty()
    {
        if (! $this->roomData) {
            return [];
        }

        return $this->roomData->members()
            ->orderBy('wpm', 'desc')
            ->orderBy('progress_percent', 'desc')
            ->orderByRaw('finished_time_seconds IS NULL, finished_time_seconds ASC')
            ->get();
    }

    public function getRoomDataForViewProperty(): ?Room
    {
        return $this->roomData;
    }

    /**
     * Data EXP nyata untuk panel hasil match (mengganti angka hardcoded lama).
     * - earned  : EXP yang diperoleh user dari match ini (dari room_members.xp_earned).
     * - level   : levelData() user TERKINI (level, progress, needed) — satu sumber
     *             dengan profil/navigation, diturunkan dari total_xp.
     * Null-safe untuk guest / sebelum EXP diberikan.
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

        $participants = $room->members->where('user_id', '!=', $room->host_id);

        return $participants->count() > 0 && $participants->where('is_ready', false)->count() === 0;
    }

    /**
     * True kalau sudden death sedang aktif (sudah ada minimal satu player
     * finish, room masih racing). Dipakai di view untuk memunculkan/
     * menyembunyikan badge countdown.
     */
    public function getSuddenDeathActiveProperty(): bool
    {
        $room = $this->roomData;

        return (bool) ($room && $room->status === 'racing' && $room->countdown_started_at);
    }

    /**
     * Sisa waktu sudden death dalam DETIK MUNDUR (15 → 0), bukan elapsed.
     * Inilah angka yang seharusnya dipakai di view/badge countdown untuk
     * player yang belum selesai — ganti tampilan yang sebelumnya hitung
     * maju dengan properti ini.
     */
    public function getSuddenDeathRemainingProperty(): int
    {
        $room = $this->roomData;

        if (! $room || ! $room->countdown_started_at) {
            return self::SUDDEN_DEATH_SECONDS;
        }

        $elapsed = now()->diffInSeconds($room->countdown_started_at, true);

        return max(0, self::SUDDEN_DEATH_SECONDS - (int) floor($elapsed));
    }

    /**
     * Waktu absolut (ISO string) kapan race resmi mulai. Dipakai klien untuk
     * menghitung mundur overlay 3-2-1 secara sinkron. Null jika belum di-set.
     */
    public function getRaceStartsAtProperty(): ?string
    {
        $room = $this->roomData;

        return $room && $room->race_starts_at
            ? $room->race_starts_at->toIso8601String()
            : null;
    }

    public function render()
    {
        return view('livewire.multiplayer-lobby')->layout('layouts.app');
    }

    public function startRace(): void
    {
        // dd(config('broadcasting.default'));
        $room = Room::where('code', $this->roomCode)->first();

        if (! $room || $room->host_id !== Auth::id()) {
            return;
        }

        RoomMember::where('room_id', $room->id)->update([
            'progress_percent' => 0,
            'wpm' => 0,
            'accuracy' => 100,
            'finished_time_seconds' => null,
            'place' => null,
            // Defense in depth: bersihkan penanda EXP setiap race baru dimulai,
            // agar jalur apa pun ke startRace() tetap bisa memberi EXP sekali.
            'xp_earned' => null,
        ]);

        $room->update([
            'status' => 'racing',
            // Jaga-jaga tambahan (defense in depth): pastikan sudden death
            // timer selalu bersih setiap kali race BARU dimulai, apa pun
            // penyebabnya. playAgain() sudah reset ini duluan, tapi kalau
            // suatu saat ada jalur lain yang memanggil startRace() tanpa
            // lewat playAgain(), race tetap tidak akan kebawa timer basi.
            'countdown_started_at' => null,
            // Titik START race ditetapkan SERVER: now() + 3 detik. Semua klien
            // menghitung mundur ke waktu absolut ini -> countdown 3-2-1 sinkron,
            // delay jaringan tidak lagi menggeser start antar-layar.
            'race_starts_at' => now()->addSeconds(self::COUNTDOWN_SECONDS),
        ]);

        $this->step = 'racing';
        $this->showResultModal = false;

        // logger('Broadcasting RoomUpdated');

        broadcast(new RoomUpdated($this->roomCode));
    }

    public function checkRoomStatus(): void
    {
        if (! $this->roomCode) {
            return;
        }

        $room = Room::where('code', $this->roomCode)->first();

        if (! $room) {
            return;
        }

        if ($room->status === 'racing') {
            $this->step = 'racing';
        }
    }
}
