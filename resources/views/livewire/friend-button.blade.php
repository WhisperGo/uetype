{{-- Contextual friend action button: renders the right control (add / cancel / accept /
     remove / status) based on the viewer's relationship with the target user. --}}
<div>
    @php $relation = $this->relation; @endphp

    @switch($relation)
        {{-- Self: no action (usually already redirected to /profile). --}}
        @case('self')
            @break

        {{-- Already friends: show status + remove option (revealed on hover). --}}
        @case('friends')
            <div class="group inline-flex items-center gap-2">
                <span class="px-4 py-2 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg inline-flex items-center gap-1.5">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                    {{ __('friends.relation_friends') }}
                </span>
                <button wire:click="removeFriend"
                    wire:confirm="{{ __('friends.confirm_remove', ['name' => $target->username]) }}"
                    class="opacity-0 group-hover:opacity-100 px-3 py-2 font-mono text-xs text-danger border border-danger/30 rounded-lg hover:bg-danger/10 transition">
                    {{ __('friends.remove') }}
                </button>
            </div>
            @break

        {{-- Our request is still pending: show status + cancel. --}}
        @case('sent')
            <div class="inline-flex items-center gap-2">
                <span class="px-4 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg">{{ __('friends.request_sent') }}</span>
                <button wire:click="cancelRequest"
                    class="px-3 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                    {{ __('friends.cancel') }}
                </button>
            </div>
            @break

        {{-- Incoming request from the target: accept / reject right here. --}}
        @case('incoming')
            <div class="inline-flex items-center gap-2">
                <x-btn-gold size="wide" wire:click="acceptRequest">{{ __('friends.accept') }}</x-btn-gold>
                <x-btn-ghost size="wide" wire:click="rejectRequest">{{ __('friends.reject') }}</x-btn-ghost>
            </div>
            @break

        {{-- No relationship yet: send a friend request. --}}
        @default
            <x-btn-gold size="wide" class="inline-flex items-center gap-1.5" wire:click="sendRequest">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 5v14m-7-7h14" /></svg>
                {{ __('friends.add_friend') }}
            </x-btn-gold>
    @endswitch

    {{-- Listen for real-time broadcasts (relayed by the global toast via a window event)
         so the button status refreshes without a reload. --}}
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
