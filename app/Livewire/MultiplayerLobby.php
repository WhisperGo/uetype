<?php

namespace App\Livewire;

use App\Events\RoomUpdated;
use App\Models\Room;
use App\Models\RoomMember;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Component;

class MultiplayerLobby extends Component
{
    public string $step = 'choose';

    public string $roomCode = '';

    public array $joinCodeInput = ['', '', '', '', '', ''];

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
        ]);

        $this->roomCode = $code;
        $this->step = 'waiting';
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
            [
                'room_id' => $room->id,
                'user_id' => Auth::id(),
            ],
            [
                'is_ready' => false,
            ],
        );

        $this->roomCode = $code;
        $this->step = 'waiting';

        broadcast(new RoomUpdated($code))->toOthers();
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
    }

    public function getRoomDataProperty(): ?Room
    {
        if ($this->step !== 'waiting' || empty($this->roomCode)) {
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
}
