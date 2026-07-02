<?php

namespace App\Livewire;

use App\Events\RoomUpdated;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\Text;
use Illuminate\Support\Facades\Auth;
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

        if (\Illuminate\Support\Facades\File::exists($path)) {
            $jsonString = \Illuminate\Support\Facades\File::get($path);
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
            session()->flash('error', 'Kode harus 6 digit lengkap!');
            return;
        }

        $room = Room::where('code', $code)->where('status', 'waiting')->first();

        if (! $room) {
            session()->flash('error', 'Kamar tidak ditemukan atau game sudah dimulai.');
            return;
        }

        if ($room->members()->count() >= 5) {
            session()->flash('error', 'Kamar sudah penuh! Maksimal 5 pemain.');
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

        if (!$room) return;

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
        $members = RoomMember::where('room_id', $roomId)
            ->orderBy('wpm', 'desc')
            ->orderBy('progress_percent', 'desc')
            ->orderByRaw('finished_time_seconds IS NULL, finished_time_seconds ASC')
            ->get();

        foreach ($members as $index => $member) {
            $member->update([
                'place' => $index + 1
            ]);
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
        if (!$room || $room->status !== 'racing') return;

        $member = RoomMember::where('room_id', $room->id)->where('user_id', Auth::id())->first();
        if ($member && !$member->finished_at) {
            $updateData = [
                'progress_percent' => min(100, max(0, $progressPercent)),
                'wpm' => $liveWpm,
                'accuracy' => min(100, max(0, $accuracy)),
            ];

            if ($progressPercent >= 100) {
                // absolute: true -> cegah hasil negatif (Carbon 3 default-nya signed diff)
                $updateData['finished_time_seconds'] = $room->updated_at->diffInSeconds(now(), true);

                $alreadyFinishedCount = RoomMember::where('room_id', $room->id)
                    ->whereNotNull('finished_time_seconds')
                    ->count();

                $updateData['place'] = $alreadyFinishedCount + 1;

                if ($alreadyFinishedCount === 0 && !$room->countdown_started_at) {
                    $room->update([
                        'countdown_started_at' => now()
                    ]);
                }
                $this->showResultModal = true;
            }

            $member->update($updateData);

            broadcast(new RoomUpdated($this->roomCode))->toOthers();
        }
    }

    public function checkSuddenDeath(): void
    {
        if (!$this->roomCode || $this->step !== 'racing') return;

        $room = Room::where('code', $this->roomCode)->first();
        if (!$room || !$room->countdown_started_at) return;

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

            // Lapisan kedua: perintahkan Alpine di klien ini untuk mengunci
            // input SEKARANG JUGA. Timer lokal Alpine sudah menangani kasus
            // normal, tapi tab yang di-background bisa membuat setInterval
            // ter-throttle sehingga hitung mundur lokal telat. Poll 1 detik
            // ini adalah jaring pengaman yang menjamin input tetap terkunci
            // begitu server resmi menutup room.
            $this->dispatch('force-finish');

            broadcast(new RoomUpdated($this->roomCode))->toOthers();
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
        if (!$this->roomData) return [];
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
            'finished_time_seconds' => null
        ]);

        $room->update([
            'status' => 'racing',
            // Jaga-jaga tambahan (defense in depth): pastikan sudden death
            // timer selalu bersih setiap kali race BARU dimulai, apa pun
            // penyebabnya. playAgain() sudah reset ini duluan, tapi kalau
            // suatu saat ada jalur lain yang memanggil startRace() tanpa
            // lewat playAgain(), race tetap tidak akan kebawa timer basi.
            'countdown_started_at' => null,
        ]);

        $this->step = 'racing';
        $this->showResultModal = false;

        // logger('Broadcasting RoomUpdated');

        broadcast(new RoomUpdated($this->roomCode));
    }

    public function checkRoomStatus(): void
    {
        if (!$this->roomCode) {
            return;
        }

        $room = Room::where('code', $this->roomCode)->first();

        if (!$room) {
            return;
        }

        if ($room->status === 'racing') {
            $this->step = 'racing';
        }
    }
}