/**
 * Echo subscriptions for multiplayer: the room lifecycle channel (join/ready/start) and
 * the high-frequency race channel (opponent positions).
 *
 * Split out from race-arena.js because it needs the global `Livewire`. The original block
 * lived in @script (not @assets) for exactly that reason; in a module, the same dependency
 * is satisfied by waiting for the `livewire:init` event -- Livewire may not exist yet when
 * the module runs.
 */
// Alpine component for the lobby chat panel (partials/room-chat.blade.php). Registered on
// 'alpine:init' so it's available before the view mounts. Message state lives here (not in
// Livewire) because the chat is broadcast-only & ephemeral.
document.addEventListener('alpine:init', () => {
    // joinLabel/leaveLabel/kickLabel: local templates with ':name', filled from the view.
    window.Alpine.data('roomChat', ({ me, youLabel, joinLabel, leaveLabel, kickLabel }) => ({
        me,
        youLabel,
        joinLabel,
        leaveLabel,
        kickLabel,
        draft: '',
        messages: [],

        init() {
            // Message from ANOTHER participant (relayed by the '.room.message' listener).
            this._onRemote = (e) => {
                const d = e.detail || {};
                // Ignore an echo of our own message if one slips through (we send with
                // ->toOthers, but this guards against a future switch to broadcast-to-all).
                if (Number(d.senderId) === Number(this.me)) { return; }
                this.push({ username: d.senderUsername, body: d.body, mine: false });
            };
            window.addEventListener('room-message-received', this._onRemote);

            // Presence notice (join/leave/kick) -> a centered system message.
            this._onPresence = (e) => {
                const d = e.detail || {};
                const tpl = {
                    leave: this.leaveLabel,
                    kick: this.kickLabel,
                }[d.action] || this.joinLabel;
                this.push({ system: true, body: tpl.replace(':name', d.username || '') });
            };
            window.addEventListener('room-presence-changed', this._onPresence);
        },

        destroy() {
            window.removeEventListener('room-message-received', this._onRemote);
            window.removeEventListener('room-presence-changed', this._onPresence);
        },

        send() {
            const body = this.draft.trim();
            if (body === '') { return; }
            this.draft = '';
            // Optimistic: show it immediately on the sender's side.
            this.push({ username: this.youLabel, body, mine: true });
            // Broadcast to the other participants (server broadcasts ->toOthers, no storage).
            this.$wire.sendRoomMessage(body);
        },

        push(msg) {
            this.messages.push(msg);
            // Cap the buffer so it doesn't grow unbounded over a long-lived room.
            if (this.messages.length > 200) { this.messages.splice(0, this.messages.length - 200); }
            this.$nextTick(() => {
                if (this.$refs.log) { this.$refs.log.scrollTop = this.$refs.log.scrollHeight; }
            });
        },
    }));
});

document.addEventListener('livewire:init', () => {
    let currentRoomChannel = null;
    let currentRaceChannel = null;

    const leaveChannels = () => {
        if (currentRoomChannel) {
            window.Echo.leave(currentRoomChannel);
            currentRoomChannel = null;
        }
        if (currentRaceChannel) {
            window.Echo.leave(currentRaceChannel);
            currentRaceChannel = null;
        }
        // Clear opponent positions so the next room doesn't carry stale state over.
        if (window.Alpine && window.Alpine.store('race')) {
            window.Alpine.store('race').reset();
        }
    };

    Livewire.on('subscribe-room', (event) => {
        const room = event.room;

        leaveChannels();

        // Lifecycle channel (low-frequency): join/ready/leave/start/finish -> still a full Livewire re-render.
        currentRoomChannel = `room.${room}`;
        window.Echo
            .channel(currentRoomChannel)
            .listen('.room.updated', () => {
                Livewire.dispatch('room-updated');
            })
            // Lobby chat: rides the same room channel. Broadcast-only (not stored), so just
            // relay to a window event -> picked up by Alpine (roomChat) in the view.
            .listen('.room.message', (e) => {
                window.dispatchEvent(new CustomEvent('room-message-received', { detail: e }));
            })
            // Presence notice (join/leave/kick) -> a centered system message in the chat panel.
            .listen('.room.presence', (e) => {
                window.dispatchEvent(new CustomEvent('room-presence-changed', { detail: e }));
            });

        // Race channel (high-frequency): opponent mascot positions applied straight to the Alpine store, no Livewire round-trip.
        currentRaceChannel = `race.${room}`;
        window.Echo
            .channel(currentRaceChannel)
            .listen('.race.progress', (e) => {
                if (window.Alpine && window.Alpine.store('race')) {
                    window.Alpine.store('race').apply(e.userId, e.progressData || {});
                }
            })
            .listen('.race.sudden_death', (e) => {
                // The server sends the time LEFT, so the client's own clock never enters the
                // calculation. Deriving it from an absolute end timestamp instead counted any
                // clock skew as elapsed time: a browser running 15+ seconds fast read "0 left"
                // and locked the player out of a race they still had a full window to finish.
                // Same reason the 3-2-1 countdown sends a relative duration.
                //
                // The endTimeIso branch only serves client bundles cached from before this
                // change; it can go once those have rolled over.
                const remaining = typeof e.remainingSeconds === 'number'
                    ? Math.max(0, e.remainingSeconds)
                    : Math.max(0, Math.ceil((new Date(e.endTimeIso).getTime() - Date.now()) / 1000));

                window.dispatchEvent(new CustomEvent('race-sudden-death', {
                    detail: { remaining }
                }));
            });
    });

    Livewire.on('leave-room', () => {
        leaveChannels();
    });
});
