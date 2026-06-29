<?php

use function Livewire\Volt\{state, rules, on, layout, computed};
use App\Models\Room;
use App\Models\RoomMember;
use App\Events\RoomUpdated;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Auth;

// Set Layout Utama
layout('layouts.app');

// State/Properti Komponen
state([
    'step' => 'choose', // choose, waiting, racing
    'roomCode' => '',
    'joinCodeInput' => ['', '', '', '', '', ''],
]);

// Jalur Listener WebSocket Reverb untuk realtime sync
on([
    'echo:room.{roomCode},.room.updated' => function () {
        // Biarkan kosong untuk memicu re-render otomatis komponen
    },
]);

// ➕ AKSI: BUAT ROOM BARU (HOST)
$createRoom = function () {
    $user = Auth::user();

    // Bersihkan jika user nyangkut di room lain
    RoomMember::where('user_id', $user->id)->delete();

    // Generate 6 digit kode acak kapital
    $code = strtoupper(Str::random(6));

    // Paragraf teks balapan
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
        'is_ready' => true, // Host otomatis ready
    ]);

    $this->roomCode = $code;
    $this->step = 'waiting';
};

// 🤝 AKSI: JOIN ROOM VIA KODE (PARTICIPANT)
$joinRoom = function () {
    $code = strtoupper(implode('', $this->joinCodeInput));

    if (strlen($code) !== 6) {
        session()->flash('error', 'Kode harus 6 digit lengkap!');
        return;
    }

    $room = Room::where('code', $code)->where('status', 'waiting')->first();

    if (!$room) {
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
};

// ⚡ AKSI: TOGGLE STATUS READY (PARTICIPANT)
$toggleReady = function () {
    $room = Room::where('code', $this->roomCode)->first();
    if (!$room) {
        return;
    }

    $member = RoomMember::where('room_id', $room->id)->where('user_id', Auth::id())->first();
    if ($member && $room->host_id !== Auth::id()) {
        $member->update([
            'is_ready' => !$member->is_ready,
        ]);

        broadcast(new RoomUpdated($this->roomCode))->toOthers();
    }
};

// 🚪 AKSI: LEAVE ROOM
$leaveRoom = function () {
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
};

// Ambil data room untuk dirender di HTML view via Computed Properties
$roomData = computed(function () {
    if ($this->step === 'waiting' && $this->roomCode) {
        $room = Room::with(['members.user', 'host'])
            ->where('code', $this->roomCode)
            ->first();
        if (!$room) {
            $this->step = 'choose';
            return null;
        }
        return $room;
    }
    return null;
});

$isHost = computed(function () {
    $room = $this->roomData;
    return $room ? $room->host_id === Auth::id() : false;
});

$allReady = computed(function () {
    $room = $this->roomData;
    if (!$room) {
        return false;
    }

    $participants = $room->members->where('user_id', '!=', $room->host_id);
    return $participants->count() > 0 && $participants->where('is_ready', false)->count() === 0;
});
?>

<div class="max-w-5xl px-4 mx-auto py-10 sm:px-6 lg:px-8 text-typing-text">

    <!-- TAMPILAN ERROR NOTIFIKASI -->
    @if (session()->has('error'))
        <div class="p-4 mb-6 text-sm text-red-400 bg-red-950/40 border border-red-900 rounded-2xl">
            {{ session('error') }}
        </div>
    @endif

    <!-- ===================================================================== -->
    <!-- 1. HALAMAN PILIH: CREATE OR JOIN ROOM -->
    <!-- ===================================================================== -->
    @if ($this->step === 'choose')
        <div class="grid grid-cols-1 gap-8 md:grid-cols-2 items-stretch mt-10">

            <!-- KARTU KIRI: CREATE ROOM -->
            <div
                class="flex flex-col items-center justify-center p-8 border bg-typing-surface/50 border-white/5 rounded-3xl text-center shadow-lg relative overflow-hidden group">
                <div
                    class="w-20 h-20 mb-6 flex items-center justify-center text-4xl bg-white/5 rounded-2xl border border-white/10 group-hover:border-typing-accent transition duration-300">
                    ⌨️</div>
                <h3 class="text-xl font-sans font-bold tracking-wider text-typing-text mb-2 uppercase">Create Room</h3>
                <p class="text-sm text-typing-muted max-w-xs mb-8">Start a new room and get a shareable code for your
                    friends</p>
                <button wire:click="createRoom"
                    class="px-6 py-3 bg-[#cbb38a] hover:bg-[#bfa57a] text-black font-sans font-bold uppercase tracking-wider rounded-xl transition duration-200 shadow-md">
                    Create Room
                </button>
            </div>

            <!-- KARTU KANAN: JOIN ROOM -->
            <div
                class="flex flex-col items-center justify-center p-8 border bg-typing-surface/50 border-white/5 rounded-3xl text-center shadow-lg relative overflow-hidden group">
                <div
                    class="w-20 h-20 mb-6 flex items-center justify-center text-4xl bg-white/5 rounded-2xl border border-white/10 group-hover:border-typing-accent transition duration-300">
                    🎮</div>
                <h3 class="text-xl font-sans font-bold tracking-wider text-typing-text mb-2 uppercase">Join Room</h3>
                <p class="text-sm text-typing-muted max-w-xs mb-6">Enter the code your friend shared with you</p>

                <!-- KOTAK PIN INPUT (6 Karakter) -->
                <div class="flex gap-2 mb-6">
                    @foreach (range(0, 5) as $index)
                        <input type="text" wire:model="joinCodeInput.{{ $index }}" maxlength="1"
                            class="w-12 h-14 text-center font-mono text-xl font-bold uppercase bg-typing-bg border border-white/10 rounded-xl focus:border-typing-accent focus:ring-0 text-typing-text"
                            x-on:keyup="if($el.value.length == 1 && {{ $index }} < 5) { $el.nextElementSibling.focus() } else if($el.value.length == 0 && {{ $index }} > 0) { $el.previousElementSibling.focus() }" />
                    @endforeach
                </div>

                <button wire:click="joinRoom"
                    class="px-8 py-3 bg-[#1a2333] border border-white/10 text-typing-text hover:bg-white/5 font-sans font-semibold uppercase tracking-wider rounded-xl transition duration-200">
                    Join Room
                </button>
            </div>
        </div>
    @endif

    <!-- ===================================================================== -->
    <!-- 2. HALAMAN RUANG TUNGGU: WAITING ROOM -->
    <!-- ===================================================================== -->
    @if ($this->step === 'waiting' && $this->roomData)
        <div class="space-y-8" wire:poll.5s>

            <!-- BANNER ATAS: TAMPILAN KODE ROOM -->
            <div
                class="p-6 border bg-typing-surface/40 border-white/5 rounded-2xl flex flex-col sm:flex-row items-center justify-between gap-4">
                <div>
                    <span class="text-xs font-mono tracking-widest text-typing-muted uppercase">Room Code - Share with
                        friends</span>
                    <h2 class="text-4xl font-mono font-black tracking-[0.3em] text-white mt-1">
                        {{ $this->roomData->code }}</h2>
                </div>
                <button
                    onclick="navigator.clipboard.writeText('{{ $this->roomData->code }}'); alert('Kode kamar disalin!')"
                    class="px-5 py-2.5 bg-white/5 border border-white/10 hover:bg-white/10 font-sans text-xs font-bold uppercase tracking-wider rounded-xl transition">
                    Copy Code
                </button>
            </div>

            <!-- DAFTAR ESTIMASI PEMAIN (MAX 5 SLOT) -->
            <div>
                <div class="flex justify-between items-center mb-4">
                    <h3 class="text-xs uppercase tracking-widest text-typing-muted font-mono font-bold">Players</h3>
                    <span class="text-xs font-mono text-[#cbb38a] font-bold">{{ $this->roomData->members->count() }} / 5
                        Joined</span>
                </div>

                <div class="grid grid-cols-2 gap-4 sm:grid-cols-5">
                    @foreach (range(0, 4) as $i)
                        @php $member = $this->roomData->members->values()->get($i); @endphp

                        @if ($member)
                            <!-- SLOT TERISI -->
                            <div
                                class="p-5 border flex flex-col items-center justify-center text-center rounded-2xl relative transition duration-300 {{ $member->user_id === Auth::id() ? 'bg-[#1a2333]/60 border-typing-accent' : 'bg-typing-surface/40 border-white/5' }}">
                                <div
                                    class="w-14 h-14 rounded-xl overflow-hidden bg-white/5 mb-3 flex items-center justify-center text-xl">
                                    @if ($member->user->avatar)
                                        <img src="{{ $member->user->avatar }}" referrerpolicy="no-referrer"
                                            class="w-full h-full object-cover">
                                    @else
                                        👨‍💻
                                    @endif
                                </div>
                                <span
                                    class="font-sans text-sm font-bold truncate max-w-[100px]">{{ $member->user->username }}</span>

                                <div class="mt-3 w-full">
                                    @if ($member->user_id === $this->roomData->host_id)
                                        <span
                                            class="inline-block w-full px-2 py-1 text-[0.65rem] font-bold uppercase tracking-wider bg-[#cbb38a] text-black rounded-md">HOST</span>
                                    @else
                                        <span
                                            class="inline-block w-full px-2 py-1 text-[0.65rem] font-bold uppercase tracking-wider rounded-md {{ $member->is_ready ? 'bg-emerald-950 text-emerald-400 border border-emerald-800' : 'bg-zinc-800 text-zinc-400' }}">
                                            {{ $member->is_ready ? 'READY' : 'NOT READY' }}
                                        </span>
                                    @endif
                                </div>
                            </div>
                        @else
                            <!-- SLOT KOSONG -->
                            <div
                                class="p-5 border border-dashed border-white/10 flex flex-col items-center justify-center text-center rounded-2xl opacity-40">
                                <div
                                    class="w-12 h-12 rounded-full border border-dashed border-white/20 mb-2 flex items-center justify-center font-mono text-sm">
                                    ?</div>
                                <span class="text-xs font-mono text-typing-muted">Empty Slot</span>
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>

            <!-- LOBBY CAPACITY BAR -->
            <div class="space-y-1">
                <div class="h-2 w-full bg-zinc-900 rounded-full overflow-hidden border border-white/5">
                    <div class="h-full bg-[#cbb38a] transition-all duration-300"
                        style="width: {{ ($this->roomData->members->count() / 5) * 100 }}%"></div>
                </div>
            </div>

            <!-- PANEL AKSI BUTTON BAWAH -->
            <div class="pt-6 border-t border-white/5 flex gap-4">
                @if ($this->isHost)
                    @php($disabledAttribute = $this->allReady ? '' : 'disabled')
                    <button {{ $disabledAttribute }}
                        class="px-6 py-3 font-sans text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $this->allReady ? 'bg-[#cbb38a] hover:bg-[#bfa57a] text-black shadow-md' : 'bg-zinc-800 text-zinc-500 cursor-not-allowed border border-white/5' }}">
                        Start Race
                    </button>
                @else
                    <button wire:click="toggleReady"
                        class="px-6 py-3 font-sans text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $this->roomData->members->where('user_id', Auth::id())->first()?->is_ready ? 'bg-emerald-600 text-white hover:bg-emerald-500' : 'bg-[#cbb38a] hover:bg-[#bfa57a] text-black' }}">
                        {{ $this->roomData->members->where('user_id', Auth::id())->first()?->is_ready ? "I'm Not Ready" : "I'm Ready" }}
                    </button>
                @endif

                <button wire:click="leaveRoom"
                    class="px-6 py-3 bg-transparent border border-white/10 text-typing-muted hover:text-typing-text hover:bg-white/5 font-sans text-sm font-bold uppercase tracking-wider rounded-xl transition">
                    Leave Room
                </button>
            </div>
        </div>
    @endif
</div>
