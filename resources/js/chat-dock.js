/**
 * Chat overlay dock: hides the bubble & drawer while a typing/racing session is active.
 *
 * The bubble used to be DRAGGABLE, and roughly 140 lines here existed to serve that:
 * an edge-distance anchor model, viewport clamping, zoom-proofing, and a tap-vs-drag
 * discriminator. All of it is gone, because the feature cost more than it bought:
 *
 *  - It KILLED keyboard access. Telling a tap from a drag meant deciding on pointerup,
 *    so the button carried only @pointerdown and no @click -- and Enter/Space, which the
 *    browser delivers as `click`, reached nobody. Keyboard-only users could not open chat
 *    on any page.
 *  - A 4px movement threshold turned a slightly shaky click into a drag, so the chat
 *    silently failed to open -- worst for exactly the users with the least steady aim.
 *  - The position lived in `window` only, so a reload threw it away. An affordance that
 *    forgets teaches the user their action meant nothing.
 *  - Nothing advertised it but `cursor-grab`, absent on mobile, where a movable bubble
 *    would have mattered most.
 *
 * And a movable bubble made every OTHER fixed element unplaceable: no notification could
 * know where the corner it must avoid actually was. Pinning it (bottom-right, via Tailwind
 * in the view) is what lets `.notif-lane` own the opposite corner with certainty.
 *
 * What is left is small enough to inline, but stays a module on purpose: an inline
 * <script> in the Blade view re-runs on every component render.
 */

export default function chatOverlayDock(open, unread) {
    return {
        open,
        // Unread DM count for the FAB badge, entangled with the server property. Seeded and
        // reconciled by the server each render; bumped client-side (see the
        // @chat-unread-bump.window handler in the view) while the drawer is closed, so the
        // badge lights up in real time without a Livewire round-trip.
        unread,
        hidden: false,

        init() {
            // Hide while a typing/racing session is active; also close the drawer.
            window.addEventListener('test-activity', (e) => {
                this.hidden = !!(e.detail && e.detail.active);
                if (this.hidden) this.open = false;
            });
            // Page change: reset hide (the old page's test session has ended).
            document.addEventListener('livewire:navigated', () => { this.hidden = false; });
        },
    };
}
