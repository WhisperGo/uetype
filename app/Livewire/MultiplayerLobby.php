<?php

namespace App\Livewire;

use App\Events\RaceProgressUpdated;
use App\Events\RoomUpdated;
use App\Events\SuddenDeathTriggered;
use App\Models\MultiplayerMatchHistory;
use App\Models\Room;
use App\Models\RoomMember;
use App\Services\AntiCheatService;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The multiplayer race lobby and arena: create/join, ready up, run the synced
 * countdown, then race. Persists a server-recomputed Net WPM (never the client's),
 * broadcasts room/race state, and finalizes placements and XP at the end.
 */
class MultiplayerLobby extends Component
{
    public string $step = 'choose';

    public string $roomCode = '';

    public array $joinCodeInput = ['', '', '', '', '', ''];

    public string $typedText = '';

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

    /** Merakit kalimat acak dari wordlist JSON (dipakai createRoom() dan playAgain()). */
    private function generateRaceText(): string
    {
        $textToType = "And i know we were perfect but i never felt this way for no one and i just can't imagine how you could be so okay now that I gone guess you did mean what you wrote in that song about me cause you said forever now i drive alone past";

        $path = base_path('database/data/indonesian.json');

        if (File::exists($path)) {
            $jsonString = File::get($path);
            $data = json_decode($jsonString, true);

            if (is_array($data) && isset($data['words']) && is_array($data['words'])) {
                $wordsArray = $data['words'];
                shuffle($wordsArray);

                $limit = 45;
                $selectedWords = [];

                while (count($selectedWords) < $limit) {
                    shuffle($wordsArray);
                    $needed = $limit - count($selectedWords);
                    $selectedWords = array_merge($selectedWords, array_slice($wordsArray, 0, $needed));
                }

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

        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($code))->toOthers());
    }

    #[On('room-updated')]
    public function roomUpdated()
    {
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
            $this->showResultModal = false;
            $this->typedText = '';
            $this->hasGivenUp = false;
            $this->hasFinished = false;
            $this->resultSnapshot = [];
        }

        if ($room->status === 'finished') {
            $this->showResultModal = true;
            $this->captureResultSnapshot();
        }

        if ($room->status === 'waiting' && $this->step === 'racing') {
            $this->step = 'waiting';
            $this->typedText = '';
            $this->showResultModal = false;
            $this->hasGivenUp = false;
            $this->hasFinished = false;
            $this->resultSnapshot = [];
        }
    }

    public function finalizeRace(string $roomId): void
    {
        $room = Room::find($roomId);
        $textLength = $room ? mb_strlen($room->text_to_type) : 0;

        $members = RoomMember::with('user')
            ->where('room_id', $roomId)
            // ->orderBy('wpm', 'desc')
            ->orderBy('finished_time_seconds', 'asc')
            ->orderBy('progress_percent', 'desc')
            ->orderByRaw('finished_time_seconds IS NULL, finished_time_seconds ASC')
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
                        'finished_time_seconds' => $member->finished_time_seconds,
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
     * Server-side validity gate for a finished race result, reusing AntiCheatService.
     * Only genuine cheat signals reject (impossible WPM / inconsistent chars) --
     * NOT low throughput/short duration, which are normal for a DNF or slow finish
     * (those stay recorded, matching the "anti-cheat only" rule). totalChars ==
     * correctChars because race progress only advances on correct characters.
     */
    private function isValidRaceResult(RoomMember $member, int $correctChars): bool
    {
        $duration = (float) ($member->finished_time_seconds ?? 0);

        $reasons = app(AntiCheatService::class)
            ->check($correctChars, $correctChars, $duration)['reasons'];

        return empty(array_intersect($reasons, ['wpm_too_high', 'char_count_inconsistent']));
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

            SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode))->toOthers());
        }
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

        $newHost = RoomMember::where('room_id', $room->id)
            ->where('user_id', '!=', $leavingUserId)
            ->orderBy('id', 'asc')
            ->first();

        if ($newHost) {
            $room->update(['host_id' => $newHost->user_id]);
            $newHost->update(['is_ready' => true]);
        }
    }

    /** Bersihkan seluruh state room & kembali ke halaman create/join. */
    private function resetToChoose(): void
    {
        $this->roomCode = '';
        $this->joinCodeInput = ['', '', '', '', '', ''];
        $this->step = 'choose';
        $this->typedText = '';
        $this->showResultModal = false;
        $this->hasGivenUp = false;
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
        $wpmCheck = app(AntiCheatService::class)->check($correctChars, $correctChars, $durationSeconds);

        // Yang MENOLAK hanyalah sinyal mustahil (WPM > batas manusiawi / karakter tak
        // konsisten). Throughput rendah / durasi pendek itu keadaan wajar di awal & pemain
        // lambat -- WPM-nya memang kecil, bukan curang -- jadi angkanya dipakai apa adanya.
        $cheatReasons = array_intersect($wpmCheck['reasons'], ['wpm_too_high', 'char_count_inconsistent']);
        $netWpm = empty($cheatReasons) ? (int) round($wpmCheck['net_wpm']) : 0;

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
            $this->hasFinished = true;
        }

        $member->update($updateData);

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
            ->whereNotNull('finished_time_seconds')
            ->count();

        $member->update([
            'finished_time_seconds' => 999,
            'place' => $alreadyFinishedCount + 1,
        ]);

        $this->hasGivenUp = true;

        if ($alreadyFinishedCount === 0 && ! $room->countdown_started_at) {
            $room->update(['countdown_started_at' => now()]);
            $room->refresh();

            SafeBroadcast::run(fn () => broadcast(new SuddenDeathTriggered(
                $this->roomCode,
                $room->countdown_started_at->copy()->addSeconds(self::SUDDEN_DEATH_SECONDS)->toIso8601String(),
            )));
        }

        $this->dispatch('force-finish');

        $unfinished = RoomMember::where('room_id', $room->id)
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

            // Peringkat default (DNF) untuk pemain yang belum selesai.
            RoomMember::where('room_id', $room->id)
                ->whereNull('finished_time_seconds')
                ->update([
                    'finished_time_seconds' => 999, // Penanda DNF (Did Not Finish)
                ]);

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
            $this->showResultModal = false;
            $this->typedText = '';
            $this->hasGivenUp = false;
            $this->hasFinished = false;
            $this->resultSnapshot = [];

            SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode))->toOthers());
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

    public function getOrderedMembersProperty()
    {
        $room = $this->roomData;

        if (! $room) {
            return collect();
        }

        return $room->members
            ->sortBy('id')
            ->sortByDesc(fn ($member) => $member->user_id === $room->host_id)
            ->values();
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

    public function getStillInRoomUserIdsProperty(): array
    {
        $room = Room::where('code', $this->roomCode)->first();

        if (! $room) {
            return [];
        }

        return RoomMember::where('room_id', $room->id)->pluck('user_id')->all();
    }

    public function getRoomDataForViewProperty(): ?Room
    {
        return $this->roomData;
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

        $participants = $room->members->where('user_id', '!=', $room->host_id);

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
        $this->showResultModal = false;
        $this->hasGivenUp = false;
        $this->resultSnapshot = [];

        // toOthers(): host SUDAH masuk 'racing' di baris atas. Tanpa ini host ikut
        // menerima room.updated-nya sendiri -> Livewire re-render di tengah hitung
        // mundur -> arena di-morph -> countdown Alpine mulai lagi dari awal.
        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($this->roomCode))->toOthers());
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
