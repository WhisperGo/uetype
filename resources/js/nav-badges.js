/**
 * Alpine component for the top nav: dropdown/mobile open state PLUS the
 * friend-request badge count.
 *
 * `open` is preserved because the nav template drives the account dropdown and the
 * mobile menu off it (this replaced the old inline `{ open: false }`).
 *
 * The nav is a static Blade partial, so the badge count is server-rendered for an
 * accurate baseline (passed into initial). It's then kept live WITHOUT a new Echo
 * subscription: toasts.js already subscribes friends.{id} and re-broadcasts every
 * change as a `friendship-updated-remote` window event -- we listen to that and
 * re-fetch the authoritative count, so the badge tracks new requests AND their
 * cancel/accept/reject (an optimistic +1 counter would drift on those).
 */
export default function navBadges(initial = 0) {
    return {
        open: false,
        friendRequests: initial,

        init() {
            this._onFriendship = () => this.refreshFriendRequests();
            window.addEventListener('friendship-updated-remote', this._onFriendship);

            // The mobile menu is now a fixed overlay, so while it is open we (a) lock body
            // scroll -- same `overflow-y-hidden` class the modal component uses -- so the
            // dimmed page behind the scrim cannot scroll, and (b) broadcast open/close so the
            // chat FAB can hide itself (it is a separate component pinned bottom-right and
            // would otherwise sit on top of the menu). Driven off the single `open` flag that
            // already backs both the dropdown and this panel.
            this.$watch('open', (isOpen) => {
                document.body.classList.toggle('overflow-y-hidden', isOpen);
                window.dispatchEvent(new CustomEvent(isOpen ? 'mobile-nav-opened' : 'mobile-nav-closed'));
            });

            // Escape closes the hamburger panel -- but ONLY when it is actually open.
            // Without that guard this handler swallows Escape from whatever is layered
            // above it (the generic modal, the chat overlay), which both use the key too.
            this._onKeydown = (e) => {
                if (e.key === 'Escape' && this.open) this.open = false;
            };
            document.addEventListener('keydown', this._onKeydown);

            // wire:navigate re-mounts this component with a fresh server-rendered
            // count, so no cleanup listener is needed -- but drop ours on navigate
            // to avoid a dangling handler on the old element.
            //
            // Closing the panel here is the whole fix for "the menu gets stuck": tapping a
            // nav link used to leave it open, so the new page loaded UNDERNEATH a panel
            // still covering it. Only Sign Out reset the flag. One navigate listener covers
            // every link -- the ones that exist today and any added later -- and it also
            // fires on back/forward, which a per-link @click never would.
            document.addEventListener(
                'livewire:navigating',
                () => {
                    window.removeEventListener('friendship-updated-remote', this._onFriendship);
                    document.removeEventListener('keydown', this._onKeydown);
                    this.open = false;
                    // Setting open=false above fires the $watch on THIS element, but the page
                    // is navigating away and the node may be torn down before it runs -- so
                    // undo the body lock and restore the FAB explicitly, or the next page can
                    // load with scroll frozen and the chat button missing.
                    document.body.classList.remove('overflow-y-hidden');
                    window.dispatchEvent(new CustomEvent('mobile-nav-closed'));
                },
                { once: true },
            );
        },

        async refreshFriendRequests() {
            try {
                const res = await fetch(window.__friendPendingCountUrl, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                });
                if (!res.ok) return;

                const data = await res.json();
                this.friendRequests = Number(data.count) || 0;
            } catch {
                // Network hiccup: keep the last known count rather than flashing to 0.
            }
        },
    };
}
