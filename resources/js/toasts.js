/**
 * One global toast for ALL notifications (friends, clan, chat).
 *
 * There used to be three separate Alpine components each rendering their own container
 * -- all three at the identical position (`fixed bottom-5 right-5 z-[60]`). A friend
 * toast and a chat toast arriving together would OVERLAP. Merging them fixed that bug
 * and dropped ~280 lines of markup that had been copied three times.
 *
 * Display policy: ONLY ONE toast at a time. A new notification replaces the old one (see
 * push()) so rapid-fire notifications don't stack up and fill the screen.
 *
 * Position is NOT set here or in the component markup: the toast is a child of
 * `.notif-lane` (resources/css/app.css), which owns the corner on behalf of every
 * notification. Anchoring itself is what once put it on top of the chat FAB.
 *
 * Nothing is shown while a typing test or race is running -- it is held and released
 * afterwards (see setTestActive()).
 *
 * Echo subscriptions stay per-channel (their payloads & display rules differ), but they
 * all push into the single slot via push().
 */

const AUTO_DISMISS_MS = 6000;

/**
 * @param {object} config supplied by Blade -- translated labels, route URLs, and the
 *   user's identity. Everything that needs server rendering lives here so this module
 *   stays pure JavaScript that can be linted & bundled.
 */
export default function toastStack(config) {
    return {
        toasts: [],
        _seq: 0,
        _dismissTimer: null,
        // A typing test or race is running: nothing may pop up until it ends. See push().
        testActive: false,
        _pending: null,

        init() {
            // Server-rendered pages queue toasts here. Registered BEFORE the Echo guard
            // below on purpose: these toasts have nothing to do with websockets, and
            // returning early would silently disable them wherever Echo is unavailable.
            this.drainQueue();
            window.addEventListener('uetype-toast', () => this.drainQueue());

            // The chat FAB already hides itself while a session runs, on the grounds that
            // even a STILL button is a distraction mid-test. A toast is strictly worse: it
            // slides in, animated, at the edge of vision, exactly while the player's WPM is
            // being measured. Held rather than dropped -- see setTestActive().
            window.addEventListener('test-activity', (e) => {
                this.setTestActive(!!(e.detail && e.detail.active));
            });

            if (!window.Echo) return; // Echo is loaded via app.js

            this.listenFriends();
            this.listenClan();
            this.listenChat();
        },

        /**
         * Take everything a page left in `window.__uetypeToasts` and show it.
         *
         * A QUEUE rather than a plain event, because this component is mounted after the
         * page slot in app.blade.php: a page dispatching an event while it initialises
         * would fire before this listener exists and nobody would hear it. Pages push to
         * the queue at parse time instead; whichever happens second -- the page's push or
         * this component mounting -- the toast still gets shown, and never twice, because
         * draining empties the queue.
         */
        drainQueue() {
            const queued = window.__uetypeToasts || [];
            window.__uetypeToasts = [];

            queued.forEach((toast) => this.push(toast));
        },

        // ---- SUBSCRIPTIONS ----

        listenFriends() {
            const channel = window.Echo.channel(`friends.${config.userId}`);

            // wire:navigate can run init() several times; drop the old listener first so
            // callbacks don't stack up (1 event = 1 toast).
            channel.stopListening('.friendship.updated');
            channel.listen('.friendship.updated', (e) => {
                // The only subscriber of friends.{id}: besides the toast, re-broadcast a
                // window event so the Friends page refreshes itself without subscribing again.
                window.dispatchEvent(new CustomEvent('friendship-updated-remote'));

                if (!e?.notification?.message) return;

                const type = e.notification.type || 'request';
                this.push({
                    icon: type === 'accepted' ? 'check' : 'user-plus',
                    tone: type === 'accepted' ? 'gold' : 'brand',
                    title: type === 'accepted' ? config.labels.friend.accepted : config.labels.friend.request,
                    message: e.notification.message,
                    href: config.routes.friends,
                });
            });

            // Online/offline status: no toast, just refreshes the friends list.
            channel.stopListening('.presence.updated');
            channel.listen('.presence.updated', () => {
                window.dispatchEvent(new CustomEvent('friendship-updated-remote'));
            });

            // Multiplayer room invite: a friend invited us to their race room. Instead of a
            // toast, this raises a full profile overlay (Accept / Decline) -- rendered by the
            // room-invite-overlay component. Forward the whole payload to it via a window event.
            channel.stopListening('.room.invitation');
            channel.listen('.room.invitation', (e) => {
                if (!e?.roomCode) return;

                window.dispatchEvent(new CustomEvent('room-invite-received', { detail: e }));
            });
        },

        listenClan() {
            const channel = window.Echo.channel(`clan.${config.userId}`);

            channel.stopListening('.clan.updated');
            channel.listen('.clan.updated', (e) => {
                window.dispatchEvent(new CustomEvent('clan-updated-remote'));

                if (!e?.notification?.message) return;

                const type = e.notification.type || 'request';
                const isWar = ['war-result', 'war-challenge', 'war-accepted', 'war-declined'].includes(type);

                this.push({
                    icon: clanIcon(type),
                    tone: clanTone(type),
                    title: config.labels.clan[type] || config.labels.clan.update,
                    message: e.notification.message,
                    href: isWar ? config.routes.clanWar : config.routes.clans,
                });
            });
        },

        listenChat() {
            const dmChannel = window.Echo.channel(`chat.${config.userId}`);

            dmChannel.stopListening('.dm.sent');
            dmChannel.listen('.dm.sent', (e) => {
                // Full payload so the chat page can show the message instantly.
                window.dispatchEvent(new CustomEvent('message-received-remote', {
                    detail: { ...e, kind: 'dm' },
                }));

                // Toast only if the conversation with this sender isn't currently open.
                if (e?.body && e.senderUsername && !this.isViewingDm(e.senderUsername)) {
                    this.push({
                        icon: 'chat',
                        tone: 'brand',
                        title: e.senderUsername,
                        message: e.body,
                        href: `${config.routes.chat}?mode=dm&with=${encodeURIComponent(e.senderUsername)}`,
                    });
                }
            });

            // DM edit/delete: forward the payload so the bubble is patched directly client-side.
            this.forwardMutations(dmChannel);

            if (!config.clanId) return;

            // Clan chat: a per-clan channel that every member subscribes to.
            const clanChannel = window.Echo.channel(`clan-chat.${config.clanId}`);

            clanChannel.stopListening('.clan-message.sent');
            clanChannel.listen('.clan-message.sent', (e) => {
                window.dispatchEvent(new CustomEvent('message-received-remote', {
                    detail: { ...e, kind: 'clan' },
                }));

                // Don't toast your own message, or when the clan chat is already open.
                if (e?.body && e.senderUsername && e.senderId !== config.userId && !this.isViewingClan()) {
                    this.push({
                        icon: 'chat',
                        tone: 'brand',
                        title: e.senderUsername,
                        message: e.body,
                        href: `${config.routes.chat}?mode=clan`,
                    });
                }
            });

            this.forwardMutations(clanChannel);
        },

        forwardMutations(channel) {
            channel.stopListening('.message.edited');
            channel.listen('.message.edited', (e) => window.dispatchEvent(
                new CustomEvent('message-mutated-remote', { detail: { ...e, action: 'edited' } })
            ));

            channel.stopListening('.message.deleted');
            channel.listen('.message.deleted', (e) => window.dispatchEvent(
                new CustomEvent('message-mutated-remote', { detail: { ...e, action: 'deleted' } })
            ));
        },

        // ---- TOAST SUPPRESSION ----
        // Read the URL: which conversation is currently open (so its toast is skipped).

        isViewingDm(username) {
            if (location.pathname.endsWith('/chat')) {
                const p = new URLSearchParams(location.search);
                if (p.get('mode') === 'dm' && p.get('with') === username) return true;
            }

            // The global overlay may also have the same thread open.
            const s = window.__chatOverlayState;

            return !!(s && s.open && s.mode === 'dm' && s.withUsername === username);
        },

        isViewingClan() {
            if (location.pathname.endsWith('/chat')) {
                if (new URLSearchParams(location.search).get('mode') === 'clan') return true;
            }

            const s = window.__chatOverlayState;

            return !!(s && s.open && s.mode === 'clan');
        },

        // ---- QUEUE (one toast at a time) ----
        // Only ONE notification shows at the bottom-right. A new notification REPLACES the
        // old one instead of stacking upward -- rapid-fire notifications (e.g. many "join
        // request"s) no longer fill the screen. Since `x-for` uses :key=id, replacing the
        // array contents triggers a leave transition (old one out) + enter (new one in) at once.

        /**
         * Enter/leave a typing or racing session.
         *
         * Held toasts use ONE slot, not a queue, because that is already the display policy
         * (see push): a newer notification replaces an older one. Buffering a backlog only
         * to show the last of it would be the same outcome with more state.
         */
        setTestActive(active) {
            this.testActive = active;

            if (this.testActive) {
                // Clear anything on screen when the session starts, so a toast that arrived
                // a moment earlier doesn't sit there for the whole test.
                if (this._dismissTimer) clearTimeout(this._dismissTimer);
                if (this.toasts.length) {
                    // Strip the id: push() builds `{ id, ...toast }`, so a stale id in the
                    // payload would spread OVER the new one, leaving the auto-dismiss timer
                    // chasing an id the rendered toast no longer has -- it would never close.
                    const { id, ...payload } = this.toasts[0]; // eslint-disable-line no-unused-vars
                    this._pending = payload;
                }
                this.toasts = [];

                return;
            }

            const held = this._pending;
            this._pending = null;

            // Shown through push() so it gets a fresh id and a full 6 seconds -- a held
            // toast must not appear already half-expired.
            if (held) this.push(held);
        },

        push(toast) {
            // Mid-session: hold it instead. Not dropped -- the player still wants to know a
            // friend messaged, just not while they're being timed.
            if (this.testActive) {
                this._pending = toast;

                return;
            }

            const id = ++this._seq;

            // Cancel the previous toast's auto-dismiss timer: otherwise the old timer could
            // call dismiss() after the new toast appears and remove it.
            if (this._dismissTimer) clearTimeout(this._dismissTimer);

            this.toasts = [{ id, ...toast }];

            this._dismissTimer = setTimeout(() => this.dismiss(id), AUTO_DISMISS_MS);
        },

        dismiss(id) {
            this.toasts = this.toasts.filter((t) => t.id !== id);
        },
    };
}

function clanIcon(type) {
    if (type === 'accepted' || type === 'war-accepted') return 'check';
    if (type === 'war-declined') return 'x';
    if (type === 'war-challenge') return 'swords';

    return 'group';
}

function clanTone(type) {
    if (type === 'accepted' || type === 'war-accepted') return 'gold';
    if (type === 'war-declined') return 'danger';

    return 'brand';
}
