<?php

namespace App\Livewire;

use App\Events\RaceProgressUpdated;
use App\Events\RoomUpdated;
use App\Events\SuddenDeathTriggered;
use App\Livewire\Concerns\FinalizesRace;
use App\Livewire\Concerns\ReadsRoomState;
use App\Models\Room;
use App\Models\RoomMember;
use App\Services\AntiCheatService;
use App\Services\TextGeneratorService;
use App\Support\SafeBroadcast;
use App\Support\TypingLanguage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The multiplayer race lobby and arena: create/join, ready up, run the synced
 * countdown, then race. Persists a server-recomputed Net WPM (never the client's),
 * broadcasts room/race state, and finalizes placements and XP at the end.
 *
 * Read-model dan penutupan balapan hidup di trait-nya sendiri. Siklus-hidup-room
 * dan siklus-balapan sengaja tetap di sini: keduanya saling bertaut, jadi memecahnya
 * hanya menghasilkan trait yang saling-use tanpa menambah kejelasan.
 */
class MultiplayerLobby extends Component
{
    use FinalizesRace, ReadsRoomState;

    public string $step = 'choose';

    public string $roomCode = '';

    public array $joinCodeInput = ['', '', '', '', '', ''];

    public bool $showResultModal = false;

    public bool $hasGivenUp = false;

    public bool $hasFinished = false;

    public array $resultSnapshot = [];

    // Durasi sudden death (detik). Konstanta tunggal dipakai checkSuddenDeath() dan
    // getSuddenDeathRemainingProperty() agar selalu sinkron.
    private const SUDDEN_DEATH_SECONDS = 15;

    // Durasi countdown awal race ("3, 2, 1, GO!"). Server set race_starts_at = now() + ini
    // agar semua klien sinkron.
    private const COUNTDOWN_SECONDS = 3;

    // Kapasitas terpisah: pembalap dan penonton dibatasi masing-masing 5. Konstanta
    // tunggal supaya join/toggle/guard tak memakai angka ajaib yang tersebar.
    public const MAX_PLAYERS = 5;

    public const MAX_SPECTATORS = 5;

    public function createRoom(): void
    {
        $user = Auth::user();

        RoomMember::where('user_id', $user->id)->delete();

        $code = strtoupper(Str::random(6));

        $room = Room::create([
            'code' => $code,
            'host_id' => $user->id,
            'status' => 'waiting',
            'text_to_type' => $this->generateRaceText(),
        ]);

        RoomMember::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'role' => RoomMember::ROLE_PLAYER,
            'is_ready' => true,
            'progress_percent' => 0,
            'wpm' => 0,
            'accuracy' => 100,
            'finished_time_seconds' => null,
        ]);

        $this->roomCode = $code;
        $this->step = 'waiting';
        $this->forgetRoomCache();

        $this->dispatch('subscribe-room', room: $code);
    }

    /**
     * Teks satu balapan. Dulu method ini merakit sendiri dari wordlist -- salinan
     * logika TypingEngine, tapi HARDCODE indonesian.json. Akibatnya multiplayer tak
     * pernah mendukung bahasa Inggris, padahal solo mendukung; itu bug fitur yang
     * lahir dari duplikasi kode. Sekarang keduanya memakai TextGeneratorService yang
     * sama, jadi bahasa konten pemain ikut dihormati di sini.
     */
    private function generateRaceText(): string
    {
        return app(TextGeneratorService::class)->forRace(
            TypingLanguage::resolve(session('typing_preferences')['contentLang'] ?? null)
        );
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

        // Room "penuh" hanya untuk pembalap: slot pemain ke-6+ tidak ditolak, melainkan
        // diarahkan jadi penonton (luapan otomatis). Room baru dianggap penuh untuk
        // menonton hanya kalau kuota penonton juga habis.
        $playersFull = $room->players()->count() >= self::MAX_PLAYERS;

        if ($playersFull && $room->spectators()->count() >= self::MAX_SPECTATORS) {
            session()->flash('error', __('multiplayer.error_room_full'));

            return;
        }

        $role = $playersFull ? RoomMember::ROLE_SPECTATOR : RoomMember::ROLE_PLAYER;

        RoomMember::updateOrCreate(
            ['room_id' => $room->id, 'user_id' => Auth::id()],
            [
                'role' => $role,
                'is_ready' => false,
                'progress_percent' => 0,
                'wpm' => 0,
                'accuracy' => 100,
                'finished_time_seconds' => null,
            ]
        );

        $this->roomCode = $code;
        $this->step = 'waiting';
        $this->forgetRoomCache();

        $this->dispatch('subscribe-room', room: $code);

        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($code))->toOthers());
    }

    #[On('room-updated')]
    public function roomUpdated()
    {
        // Event ini dipicu perubahan dari pemain LAIN -> apa pun yang sempat
        // ter-cache di request ini sudah basi.
        $this->forgetRoomCache();

        $room = Room::where('code', $this->roomCode)->first();

        // Room hilang (host keluar / room dibubarkan): kembalikan pemain yang
        // tersisa ke halaman pilih, bukan dibiarkan di step 'racing' tanpa data
        // (semua blok view butuh roomData -> halaman jadi kosong).
        if (! $room) {
            $this->resetToChoose();
            // Lepas langganan channel room yang sudah tak ada.
            $this->dispatch('leave-room');

            return;
        }

        if ($room->status === 'racing' && $this->step !== 'racing') {
            $this->step = 'racing';
            $this->resetRaceOutcome();
        }

        if ($room->status === 'finished') {
            $this->showResultModal = true;
            $this->captureResultSnapshot();
        }

        if ($room->status === 'waiting' && $this->step === 'racing') {
            $this->step = 'waiting';
            $this->resetRaceOutcome();
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

            $this->forgetRoomCache();

            SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode))->toOthers());
        }
    }

    /**
     * Berpindah peran pembalap <-> penonton, hanya saat room masih 'waiting'.
     *
     * Host boleh jadi penonton (host_id terpisah dari role): ia tetap pengendali
     * yang memegang "Mulai Balapan", cuma tak ikut membalap. Karena itu tak perlu
     * reassign host di sini. Guard kapasitas per sisi (5 pemain / 5 penonton).
     */
    public function toggleSpectator(): void
    {
        $room = Room::where('code', $this->roomCode)->first();

        if (! $room || $room->status !== 'waiting') {
            return;
        }

        $member = RoomMember::where('room_id', $room->id)->where('user_id', Auth::id())->first();

        if (! $member) {
            return;
        }

        if ($member->isSpectator()) {
            if ($room->players()->count() >= self::MAX_PLAYERS) {
                session()->flash('error', __('multiplayer.error_players_full'));

                return;
            }

            // Kembali jadi pembalap: reset state race & ready. Host yang kembali jadi
            // pembalap tetap auto-ready (konsisten dengan createRoom).
            $member->update([
                'role' => RoomMember::ROLE_PLAYER,
                'is_ready' => $room->host_id === Auth::id(),
                'progress_percent' => 0,
                'wpm' => 0,
                'accuracy' => 100,
                'finished_time_seconds' => null,
                'place' => null,
                'xp_earned' => null,
            ]);
        } else {
            if ($room->spectators()->count() >= self::MAX_SPECTATORS) {
                session()->flash('error', __('multiplayer.error_spectators_full'));

                return;
            }

            $member->update([
                'role' => RoomMember::ROLE_SPECTATOR,
                'is_ready' => false,
            ]);
        }

        $this->forgetRoomCache();

        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode))->toOthers());
    }

    public function leaveRoom(): void
    {
        $room = Room::where('code', $this->roomCode)->first();

        if ($room) {
            $leavingUserId = Auth::id();

            RoomMember::where('room_id', $room->id)->where('user_id', $leavingUserId)->delete();

            $remaining = RoomMember::where('room_id', $room->id)->count();

            if ($remaining === 0) {
                $room->delete();
            } else {
                $this->reassignHostIfNeeded($room, $leavingUserId);
            }

            SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode))->toOthers());
        }

        $this->resetToChoose();

        $this->dispatch('leave-room');
    }

    private function reassignHostIfNeeded(Room $room, int $leavingUserId): void
    {
        if ($room->host_id !== $leavingUserId) {
            return;
        }

        // Host baru diutamakan dari pembalap (mereka yang benar-benar bertanding);
        // hanya kalau tak ada pembalap tersisa, penonton jadi host-penonton.
        $newHost = RoomMember::where('room_id', $room->id)
            ->where('user_id', '!=', $leavingUserId)
            ->orderByRaw("role = '".RoomMember::ROLE_PLAYER."' DESC")
            ->orderBy('id', 'asc')
            ->first();

        if ($newHost) {
            $room->update(['host_id' => $newHost->user_id]);

            // is_ready hanya bermakna untuk pembalap; host-penonton tak perlu di-ready-kan.
            if ($newHost->isPlayer()) {
                $newHost->update(['is_ready' => true]);
            }
        }
    }

    /** Bersihkan seluruh state room & kembali ke halaman create/join. */
    private function resetToChoose(): void
    {
        $this->roomCode = '';
        $this->joinCodeInput = ['', '', '', '', '', ''];
        $this->step = 'choose';

        $this->resetRaceOutcome();

        $this->forgetRoomCache();
    }

    /**
     * Buang seluruh state hasil satu balapan.
     *
     * Dijadikan satu method karena keempat properti ini SELALU harus dibuang
     * bersamaan, dan dulu tidak: resetToChoose() dan startRace() melewatkan
     * $hasFinished. Akibatnya host yang pernah menyelesaikan balapan lalu keluar
     * dan membuat room baru mendapati panel ketiknya tersembunyi -- host tak
     * menerima broadcast room.updated miliknya sendiri (->toOthers()), jadi tak
     * ada jalur lain yang membersihkannya.
     */
    private function resetRaceOutcome(): void
    {
        $this->showResultModal = false;
        $this->hasGivenUp = false;
        $this->hasFinished = false;
        $this->resultSnapshot = [];
    }

    /**
     * Catatan integritas: parameter $liveWpm dari client SENGAJA tidak dipakai untuk
     * angka resmi. Server menghitung ulang Net WPM sendiri (karakter benar / waktu) via
     * AntiCheatService -- selaras dengan mode solo (TypingEngine::saveResult) -- supaya
     * ketik ngasal-cepat (WPM tinggi, akurasi rendah) tak bisa menyulap rekor.
     * $liveWpm tetap ada demi kompatibilitas payload client yang sudah ada.
     */
    public function updateRaceProgress(int $progressPercent, int $liveWpm = 0, int $accuracy = 100): void
    {
        $room = Room::where('code', $this->roomCode)->first();
        if (! $room || $room->status !== 'racing') {
            return;
        }

        $member = RoomMember::where('room_id', $room->id)->where('user_id', Auth::id())->first();

        // Pemain yang sudah finish tak boleh lagi mem-broadcast progress.
        if (! $member || ! is_null($member->finished_time_seconds)) {
            return;
        }

        $progressPercent = min(100, max(0, $progressPercent));
        $accuracy = min(100, max(0, $accuracy));

        // Net WPM otoritatif: diturunkan dari progres (progress% x panjang teks = karakter
        // benar, pola sama dengan finalizeRace) dan durasi race di server, BUKAN dari WPM
        // client. Karakter salah tak menambah progres, jadi ini otomatis "net".
        $textLength = mb_strlen($room->text_to_type);
        $correctChars = (int) round(($progressPercent / 100) * $textLength);

        $raceStart = $room->race_starts_at ?? $room->updated_at;
        $durationSeconds = max(0.0, (float) $raceStart->diffInSeconds(now(), true));

        // Rumus Net WPM sama persis dengan mode solo (satu sumber kebenaran).
        // totalChars = correctChars: progress hanya naik dari karakter benar, jadi net WPM
        // tak bisa dipompa dengan ketik ngasal.
        $antiCheat = app(AntiCheatService::class);
        $wpmCheck = $antiCheat->check($correctChars, $correctChars, $durationSeconds);

        // Yang MENOLAK hanyalah sinyal mustahil (WPM > batas manusiawi / karakter tak
        // konsisten). Throughput rendah / durasi pendek itu keadaan wajar di awal & pemain
        // lambat -- WPM-nya memang kecil, bukan curang -- jadi angkanya dipakai apa adanya.
        $netWpm = $antiCheat->isCheating($wpmCheck['reasons']) ? 0 : (int) round($wpmCheck['net_wpm']);

        $updateData = [
            'progress_percent' => $progressPercent,
            'wpm' => $netWpm,
            'accuracy' => $accuracy,
        ];

        $justFinished = false;
        $suddenDeathJustStarted = false;

        if ($progressPercent >= 100) {
            $justFinished = true;

            // Durasi tempuh = sekarang - race_starts_at (titik countdown selesai);
            // fallback ke updated_at hanya kalau race_starts_at kosong.
            // absolute: true -> cegah hasil negatif.
            $updateData['finished_time_seconds'] = (int) round($durationSeconds);

            $alreadyFinishedCount = RoomMember::where('room_id', $room->id)
                ->where('role', RoomMember::ROLE_PLAYER)
                ->whereNotNull('finished_time_seconds')
                ->count();

            $updateData['place'] = $alreadyFinishedCount + 1;

            $suddenDeathJustStarted = $this->startSuddenDeathIfNeeded($room);
            $this->hasFinished = true;
        }

        $member->update($updateData);
        $this->forgetRoomCache();

        // Gerakan maskot lawan: payload langsung lewat WebSocket (channel race.{code}),
        // klien lain cukup baca & geser maskot di Alpine store tanpa round-trip server.
        SafeBroadcast::run(fn () => broadcast(new RaceProgressUpdated($this->roomCode, Auth::id(), [
            'progress_percent' => $progressPercent,
            'wpm' => $netWpm,
            'accuracy' => $accuracy,
            'finished' => $justFinished,
        ]))->toOthers());

        // Pemain pertama finish -> sudden death mulai. Kirim timestamp akhir yang sama ke
        // semua klien agar countdown tersinkron; server tetap gerbang final via checkSuddenDeath().
        if ($suddenDeathJustStarted) {
            SafeBroadcast::run(fn () => broadcast(new SuddenDeathTriggered(
                $this->roomCode,
                $room->countdown_started_at->copy()->addSeconds(self::SUDDEN_DEATH_SECONDS)->toIso8601String(),
            )));
        }

        // Lifecycle (bukan sekadar gerakan): room berubah (badge, modal, place) -> re-render via RoomUpdated.
        if ($justFinished) {
            // Fast-path: kalau semua peserta sudah finish, tutup room tanpa menunggu timeout.
            $unfinished = RoomMember::where('room_id', $room->id)
                ->where('role', RoomMember::ROLE_PLAYER)
                ->whereNull('finished_time_seconds')
                ->count();

            if ($unfinished === 0) {
                $room->update(['status' => 'finished']);
                $this->finalizeRace($room->id);
                $this->captureResultSnapshot();
            }

            SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode)));
        }
    }

    public function giveUp(): void
    {
        $room = Room::where('code', $this->roomCode)->first();
        if (! $room || $room->status !== 'racing') {
            return;
        }

        $member = RoomMember::where('room_id', $room->id)->where('user_id', Auth::id())->first();

        if (! $member || ! is_null($member->finished_time_seconds)) {
            return;
        }

        $alreadyFinishedCount = RoomMember::where('room_id', $room->id)
            ->where('role', RoomMember::ROLE_PLAYER)
            ->whereNotNull('finished_time_seconds')
            ->count();

        $member->update([
            'finished_time_seconds' => RoomMember::DNF_SENTINEL_SECONDS,
            'place' => $alreadyFinishedCount + 1,
        ]);
        $this->forgetRoomCache();

        $this->hasGivenUp = true;

        if ($this->startSuddenDeathIfNeeded($room)) {
            SafeBroadcast::run(fn () => broadcast(new SuddenDeathTriggered(
                $this->roomCode,
                $room->countdown_started_at->copy()->addSeconds(self::SUDDEN_DEATH_SECONDS)->toIso8601String(),
            )));
        }

        $this->dispatch('force-finish');

        $unfinished = RoomMember::where('room_id', $room->id)
            ->where('role', RoomMember::ROLE_PLAYER)
            ->whereNull('finished_time_seconds')
            ->count();

        if ($unfinished === 0) {
            $room->update(['status' => 'finished']);
            $this->finalizeRace($room->id);
            $this->showResultModal = true;
            $this->captureResultSnapshot();
        }

        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode)));
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

        // Detik yang sudah berlalu sejak sudden death dimulai (maju, 0 -> 15), hanya
        // syarat auto-finish. Untuk tampilan mundur, pakai getSuddenDeathRemainingProperty().
        $secondsPassed = now()->diffInSeconds($room->countdown_started_at, true);

        if ($secondsPassed >= self::SUDDEN_DEATH_SECONDS) {
            $room->update(['status' => 'finished']);

            // Peringkat default (DNF) untuk pemain yang belum selesai. Hanya pembalap:
            // penonton memang tak punya finished_time_seconds dan bukan DNF.
            RoomMember::where('room_id', $room->id)
                ->where('role', RoomMember::ROLE_PLAYER)
                ->whereNull('finished_time_seconds')
                ->update([
                    'finished_time_seconds' => RoomMember::DNF_SENTINEL_SECONDS,
                ]);

            $this->forgetRoomCache();

            $this->finalizeRace($room->id);
            $this->showResultModal = true;
            $this->captureResultSnapshot();

            // Kunci input klien ini sekarang; guard di atas membuat method idempoten
            // meski dipicu beberapa klien sekaligus.
            $this->dispatch('force-finish');

            // Broadcast ke semua (bukan toOthers): klien pemicu juga perlu status final.
            SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode)));
        }
    }

    public function playAgain(): void
    {
        $room = Room::where('code', $this->roomCode)->first();
        if ($room && $room->host_id === Auth::id()) {
            $room->update([
                'status' => 'waiting',
                // Reset agar checkSuddenDeath() tak langsung auto-finish dari timer race lama.
                'countdown_started_at' => null,
                'text_to_type' => $this->generateRaceText(),
            ]);
            RoomMember::where('room_id', $room->id)->update([
                'is_ready' => false,
                'progress_percent' => 0,
                'wpm' => 0,
                'accuracy' => 100,
                'finished_time_seconds' => null,
                'place' => null,
                'xp_earned' => null, // race berikutnya bisa memberi EXP lagi
            ]);

            $this->step = 'waiting';
            $this->resetRaceOutcome();

            $this->forgetRoomCache();

            SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode))->toOthers());
        }
    }

    public function render()
    {
        // Penjaga terakhir sebelum view dievaluasi: kalau masih menahan roomCode
        // tapi room-nya sudah tak ada (host keluar), pulihkan ke halaman pilih.
        // Tanpa ini semua blok view gagal syarat -> halaman kosong.
        if ($this->step !== 'choose' && $this->roomCode !== ''
            && ! Room::where('code', $this->roomCode)->exists()) {
            $this->resetToChoose();
            $this->dispatch('leave-room');
        }

        return view('livewire.multiplayer-lobby')->layout('layouts.app');
    }

    public function startRace(): void
    {
        $room = Room::where('code', $this->roomCode)->first();

        if (! $room || $room->host_id !== Auth::id()) {
            return;
        }

        // Tak boleh mulai balapan tanpa pembalap: host bisa jadi penonton, dan jika
        // semua orang penonton, tak ada yang bertanding.
        if ($room->players()->count() === 0) {
            session()->flash('error', __('multiplayer.error_no_players'));

            return;
        }

        RoomMember::where('room_id', $room->id)->update([
            'progress_percent' => 0,
            'wpm' => 0,
            'accuracy' => 100,
            'finished_time_seconds' => null,
            'place' => null,
            'xp_earned' => null, // defense in depth: pastikan bisa memberi EXP sekali lagi
        ]);

        $room->update([
            'status' => 'racing',
            // Defense in depth: pastikan sudden death timer bersih tiap race baru.
            'countdown_started_at' => null,
            // Start ditetapkan server: now() + 3 detik, semua klien hitung mundur ke waktu
            // absolut ini agar countdown sinkron.
            'race_starts_at' => now()->addSeconds(self::COUNTDOWN_SECONDS),
        ]);

        $this->step = 'racing';
        $this->resetRaceOutcome();

        $this->forgetRoomCache();

        // toOthers(): host SUDAH masuk 'racing' di baris atas. Tanpa ini host ikut
        // menerima room.updated-nya sendiri -> Livewire re-render di tengah hitung
        // mundur -> arena di-morph -> countdown Alpine mulai lagi dari awal.
        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode))->toOthers());
    }
}
