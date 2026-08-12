/**
 * Focus mode: while a player is typing, the page frame gets out of the way.
 *
 * The signal is the EXISTING `test-activity` event -- the same one the chat FAB uses to hide
 * itself and the toast stack uses to HOLD notifications until the run is over. Nothing new is
 * broadcast here; this is one more listener on a signal that already had three, which is why
 * the multiplayer race arena picks it up for free (it dispatches the same event on mount).
 *
 * All this module does is toggle one class on <body>. Everything visible about focus mode is
 * CSS, and that is deliberate:
 *
 *  - The two elements that must react (navbar, footer) live in the layout, and the footer has
 *    no Alpine root at all. Giving it one just to fade it would be a component that exists
 *    only to hold a boolean.
 *  - "Comes back when the cursor approaches" is `:hover`. CSS does that with no JavaScript,
 *    no mousemove listener, and no frame cost while somebody is typing -- which is precisely
 *    the moment this page must not be doing extra work.
 *  - Touch devices have no hover, so they need the opposite treatment, and that is a
 *    `@media (pointer: coarse)` query. Also CSS.
 *
 * See resources/css/app.css for the rules themselves.
 */

/** The class CSS keys off. Exported so the test names the same string the module does. */
export const FOCUS_CLASS = 'is-typing';

export function registerFocusMode() {
    if (window.__focusModeRegistered) return;
    window.__focusModeRegistered = true;

    const set = (active) => {
        document.body.classList.toggle(FOCUS_CLASS, active);
    };

    window.addEventListener('test-activity', (e) => {
        // `=== true`, not a truthiness check on the event: a malformed event must resolve to
        // "not typing". Failing towards VISIBLE matters more than it looks -- the failure in
        // the other direction is a page whose navigation has vanished while nobody is typing,
        // and no obvious way for the player to get it back.
        set(e.detail?.active === true);
    });

    // A page change ends whatever session the old page was running. Without this, leaving
    // /typing mid-test lands the player on the next page with the frame still faded out.
    // This project has already shipped that shape of bug once -- the war exit link that
    // inherited pointer-events-none and left the browser Back button as the only way out.
    document.addEventListener('livewire:navigated', () => set(false));
}
