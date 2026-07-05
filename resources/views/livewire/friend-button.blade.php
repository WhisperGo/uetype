<div>
    @php $relation = $this->relation; @endphp

    @switch($relation)
        {{-- Diri sendiri: tak ada aksi (biasanya sudah dialihkan ke /profile). --}}
        @case('self')
            @break

        {{-- Sudah berteman: tampilkan status + opsi hapus (muncul saat hover). --}}
        @case('friends')
            <div class="group inline-flex items-center gap-2">
                <span class="px-4 py-2 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg inline-flex items-center gap-1.5">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                    Friends
                </span>
                <button wire:click="removeFriend"
                    wire:confirm="Hapus {{ $target->username }} dari daftar teman?"
                    class="opacity-0 group-hover:opacity-100 px-3 py-2 font-mono text-xs text-red-400/80 border border-red-900/40 rounded-lg hover:bg-red-950/30 transition">
                    Remove
                </button>
            </div>
            @break

        {{-- Permintaan kita masih menunggu: tampilkan status + batal. --}}
        @case('sent')
            <div class="inline-flex items-center gap-2">
                <span class="px-4 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg">Request Sent</span>
                <button wire:click="cancelRequest"
                    class="px-3 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                    Cancel
                </button>
            </div>
            @break

        {{-- Ada permintaan masuk dari target: terima / tolak langsung di sini. --}}
        @case('incoming')
            <div class="inline-flex items-center gap-2">
                <button wire:click="acceptRequest"
                    class="px-4 py-2 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                    Accept
                </button>
                <button wire:click="rejectRequest"
                    class="px-4 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                    Reject
                </button>
            </div>
            @break

        {{-- Belum ada relasi: kirim permintaan pertemanan. --}}
        @default
            <button wire:click="sendRequest"
                class="px-4 py-2 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition inline-flex items-center gap-1.5">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14" /></svg>
                Add Friend
            </button>
    @endswitch

    {{-- Dengarkan siaran real-time (diteruskan toast global lewat window event)
         supaya status tombol ikut menyegar tanpa reload. --}}
    @script
        <script>
            const onRemote = () => $wire.dispatch('friendship-updated');
            window.addEventListener('friendship-updated-remote', onRemote);
            document.addEventListener('livewire:navigating', () => {
                window.removeEventListener('friendship-updated-remote', onRemote);
            }, { once: true });
        </script>
    @endscript
</div>
