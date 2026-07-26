{{-- ===== ROOM INVITE NOTIFICATION =====
     A friend's race-room invitation, shown as a NON-BLOCKING card in the bottom-right corner
     (not a full-screen overlay) so it never covers the typing area -- someone in the middle
     of a serious typing test can keep going and glance at it when ready. Rendered once in the
     app layout (like the toast stack) so it can appear on ANY page.

     It has no Echo subscription of its own: <x-toast-stack /> already listens on friends.{id}
     and re-broadcasts '.room.invitation' as a `room-invite-received` window event, which this
     component consumes. The payload (inviter name + avatar + room code) arrives fully formed
     over the socket, so Accept can deep-link straight to /multiplayer?invite=CODE where
     mount() auto-joins -- no server round-trip to render the card.

     Carries NO positioning of its own: it is a child of `.notif-lane` in
     layouts/app.blade.php and sits ABOVE the toast in that lane, because an invite needs a
     decision while a toast is transient. It used to hard-code `bottom-24` to dodge the
     toast -- measured against the TOAST rather than the chat FAB, which is how two
     conventions grew apart. Auto-dismisses after a while if ignored, so it doesn't linger
     for a typist who never looks over. --}}
@auth
    <div x-data="{
        show: false,
        invite: { inviterUsername: '', inviterAvatar: null, roomCode: '' },
        mascot: '/icon/uetype_mascot.png',
        autoHideMs: 15000,
        _timer: null,
        testActive: false,
        _pending: null,
        receive(detail) {
            if (!detail?.roomCode) return;
            // Mid-test or mid-race: hold it, and crucially DON'T arm the timer. A card that
            // isn't on screen must not be counting down -- a 15s timer started during a
            // two-minute race would expire the invite before it was ever seen.
            if (this.testActive) {
                this._pending = detail;
                return;
            }
            this.invite = {
                inviterUsername: detail.inviterUsername || '',
                inviterAvatar: detail.inviterAvatar || null,
                roomCode: detail.roomCode,
            };
            this.show = true;
            // Re-arm the auto-dismiss each time a fresh invite lands.
            if (this._timer) clearTimeout(this._timer);
            this._timer = setTimeout(() => { this.show = false; }, this.autoHideMs);
        },
        setTestActive(active) {
            this.testActive = !!active;
            if (this.testActive) {
                // A card already on screen when the test starts is stashed, not dropped, so
                // it returns with a full countdown instead of a half-spent one.
                if (this.show) { this._pending = this.invite; this.dismiss(); }
                return;
            }
            const held = this._pending;
            this._pending = null;
            // Replayed through receive(), so the countdown starts from zero. A held invite
            // may be stale by now; that's harmless -- MultiplayerLobby::mount() just fails
            // to join a dead code and the player lands on the lobby as usual.
            if (held) this.receive(held);
        },
        accept() {
            const code = this.invite.roomCode;
            this.dismiss();
            if (code) window.location.href = `{{ route('multiplayer.lobby') }}?invite=${encodeURIComponent(code)}`;
        },
        decline() {
            this.dismiss();
        },
        dismiss() {
            this.show = false;
            if (this._timer) { clearTimeout(this._timer); this._timer = null; }
        },
    }"
        x-on:room-invite-received.window="receive($event.detail)"
        x-on:test-activity.window="setTestActive($event.detail && $event.detail.active)"
        x-show="show"
        x-cloak
        role="alertdialog"
        aria-modal="false"
        :aria-hidden="show ? 'false' : 'true'"
        {{-- Upward, matching the toast below it: the lane is anchored at the top, so both
             cards tuck back toward that edge rather than across the screen. --}}
        x-transition:enter="transition ease-out duration-300"
        x-transition:enter-start="opacity-0 -translate-y-4"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-200"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 -translate-y-4"
        class="pointer-events-auto overflow-hidden rounded-2xl border border-brand-bright/30 bg-surface shadow-2xl backdrop-blur">

        {{-- Accent glow strip at the top --}}
        <div class="h-1 w-full bg-gradient-to-r from-brand-bright/60 via-gold/60 to-brand-bright/60"></div>

        <div class="p-4">
            <div class="flex items-start gap-3">
                {{-- Inviter avatar (mascot fallback, mirroring x-friend-avatar) --}}
                <div class="relative shrink-0">
                    <div class="h-12 w-12 overflow-hidden rounded-xl border border-brand-bright/30 bg-foreground/5 flex items-center justify-center">
                        <template x-if="invite.inviterAvatar">
                            <img :src="invite.inviterAvatar" :alt="invite.inviterUsername" referrerpolicy="no-referrer"
                                class="h-full w-full object-cover">
                        </template>
                        <template x-if="!invite.inviterAvatar">
                            <img :src="mascot" :alt="invite.inviterUsername" class="h-4/5 w-4/5 object-contain">
                        </template>
                    </div>
                    {{-- Little swords badge to signal a race invite --}}
                    <span class="absolute -bottom-1 -right-1 flex h-5 w-5 items-center justify-center rounded-full border-2 border-surface bg-brand-bright text-background">
                        <svg class="h-2.5 w-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M14.5 17.5L3 6V3h3l11.5 11.5M13 19l6-6M16 16l4 4M19 21l2-2" />
                        </svg>
                    </span>
                </div>

                <div class="min-w-0 flex-1">
                    <p class="font-mono text-[10px] uppercase tracking-widest text-muted/70">
                        {{ __('multiplayer.invite_overlay_subtitle') }}
                    </p>
                    {{-- "<name> invited you to the game" --}}
                    <p class="mt-0.5 font-mono text-sm leading-snug text-foreground break-words">
                        <span class="font-bold text-brand-bright" x-text="invite.inviterUsername"></span><span class="text-muted">{{ __('multiplayer.invite_overlay_body') }}</span>
                    </p>
                </div>

                {{-- Dismiss (X): same as decline, for a quick brush-off without a decision. --}}
                <button type="button" x-on:click="decline()"
                    class="shrink-0 -mt-1 -mr-1 text-muted hover:text-foreground transition"
                    aria-label="{{ __('multiplayer.invite_decline') }}">
                    <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            {{-- Accept / Decline --}}
            <div class="mt-3.5 flex items-center gap-2">
                <button type="button" x-on:click="decline()"
                    class="flex-1 rounded-lg border border-border/50 px-3 py-2 font-mono text-xs font-bold uppercase tracking-wider text-muted transition hover:bg-foreground/5 hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-border">
                    {{ __('multiplayer.invite_decline') }}
                </button>
                <button type="button" x-on:click="accept()"
                    class="flex-1 rounded-lg bg-brand-bright px-3 py-2 font-mono text-xs font-bold uppercase tracking-wider text-background transition hover:bg-brand focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-bright focus-visible:ring-offset-2 focus-visible:ring-offset-surface">
                    {{ __('multiplayer.invite_accept') }}
                </button>
            </div>
        </div>
    </div>
@endauth
