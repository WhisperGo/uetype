<?php

namespace App\Livewire;

use App\Events\RoomUpdated;
use App\Models\Room;
use App\Models\RoomMember;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

use Livewire\Attributes\On;

class MultiplayerLobby extends Component
{
    public string $step = 'choose';

    public string $roomCode = '';

    public array $joinCodeInput = ['', '', '', '', '', ''];

    public string $typedText = '';

    public bool $showResultModal = false;

    public function createRoom(): void
    {
        $user = Auth::user();

        RoomMember::where('user_id', $user->id)->delete();

        $code = strtoupper(Str::random(6));
        $textToType = "And i know we were perfect but i never felt this way for no one and i just can't imagine how you could be so okay now that I gone guess you did mean what you wrote in that song about me cause you said forever now i drive alone past";

        $room = Room::create([
            'code' => $code,
            'host_id' => $user->id,
            'status' => 'waiting',
            'text_to_type' => $textToType,
        ]);

        RoomMember::create([
            'room_id' => $room->id,
            'user_id' => $user->id,
            'is_ready' => true,
            'progress' => 0,
            'wpm' => 0,
            'finished_at' => null,
        ]);

        $this->roomCode = $code;
        $this->step = 'waiting';

        $this->dispatch('subscribe-room', room: $code);
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
            ['is_ready' => false, 'progress' => 0, 'wpm' => 0, 'finished_at' => null]
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

    public function updateRaceProgress(int $progressPercent, int $liveWpm): void
    {
        $room = Room::where('code', $this->roomCode)->first();
        if (!$room || $room->status !== 'racing') return;

        $member = RoomMember::where('room_id', $room->id)->where('user_id', Auth::id())->first();
        if ($member && !$member->finished_at) {
            $updateData = [
                'progress_percent' => min(100, max(0, $progressPercent)),
                'wpm' => $liveWpm
            ];

            if ($progressPercent >= 100) {
                $updateData['finished_time_seconds'] = now()->diffInSeconds($room->updated_at);

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

        // Hitung sisa waktu sudden death
        $secondsPassed = now()->diffInSeconds($room->countdown_started_at);
        
        // Jika sudah melewati 15 detik, paksa kunci game menjadi 'finished'
        if ($secondsPassed >= 15) {
            $room->update(['status' => 'finished']);
            
            // Berikan peringkat default ke pemain yang belum selesai berdasarkan progress tertinggi
            $unfinishedMembers = RoomMember::where('room_id', $room->id)
                ->whereNull('finished_time_seconds')
                ->orderBy('progress_percent', 'desc')
                ->orderBy('wpm', 'desc')
                ->get();

            $alreadyFinishedCount = RoomMember::where('room_id', $room->id)
                ->whereNotNull('finished_time_seconds')
                ->count();

            foreach ($unfinishedMembers as $index => $m) {
                $m->update([
                    'finished_time_seconds' => 999, // Penanda tidak finish tepat waktu
                    'place' => $alreadyFinishedCount + $index + 1
                ]);
            }

            $this->showResultModal = true;
            broadcast(new RoomUpdated($this->roomCode))->toOthers();
        }
    }

    public function playAgain(): void
    {
        $room = Room::where('code', $this->roomCode)->first();
        if ($room && $room->host_id === Auth::id()) {
            $room->update(['status' => 'waiting']);
            RoomMember::where('room_id', $room->id)->update([
                'is_ready' => false,
                'progress_percent' => 0,
                'wpm' => 0,
                'finished_time_seconds' => null
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
            ->orderByRaw('finished_time_seconds IS NULL, finished_time_seconds ASC')
            ->orderBy('progress_percent', 'desc')
            ->orderBy('wpm', 'desc')
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
            'finished_time_seconds' => null
        ]);

        $room->update([
            'status' => 'racing',
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