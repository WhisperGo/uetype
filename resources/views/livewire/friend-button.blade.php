{{-- Contextual friend action button: renders the right control (add / cancel / accept /
     remove / status) based on the viewer's relationship with the target user. --}}
<div>
    @php $relation = $this->relation; @endphp

    @switch($relation)
        {{-- Self: no action (usually already redirected to /profile). --}}
        @case('self')
            @break

        {{-- Already friends: show status + remove option. --}}
        @case('friends')
            {{-- Remove is ALWAYS visible. It used to be `opacity-0 group-hover:opacity-100`,
                 which on a touch screen means it never appears at all: there is no hover, so
                 the only way to un-friend someone was to open a desktop browser. That is a
                 whole action lost on the majority of sessions, and it looked like a working
                 button to anyone testing with a mouse.

                 Revealing on hover was not protecting anything either -- `wire:confirm` below
                 already stands between the tap and the deletion. Hiding a destructive control
                 buys safety only when nothing else does. --}}
            <div class="inline-flex items-center gap-2">
                <span class="px-4 py-2 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg inline-flex items-center gap-1.5">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                    {{ __('friends.relation_friends') }}
                </span>
                <button wire:click="removeFriend"
                    wire:confirm="{{ __('friends.confirm_remove', ['name' => $target->username]) }}"
                    class="px-3 py-2 font-mono text-xs text-danger border border-danger/30 rounded-lg hover:bg-danger/10 transition">
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
