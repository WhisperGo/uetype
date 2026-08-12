/**
 * Multiplayer room navigation guards. Two behaviors, both driven by data-* flags the
 * lobby view renders on its root element (`[data-mp-flags]`), so they are INERT on every
 * page except /multiplayer (the element only exists there).
 *
 * #2 Auto-leave on page unload: a not-ready, non-host member who leaves /multiplayer (nav
 *    click, tab close, refresh-elsewhere) is removed from the room. The server
 *    (leave-beacon endpoint) decides who actually leaves; here we just fire the beacon.
 *    Only fired while WAITING: a page-unload during a race is a reload, not a departure --
 *    the row must survive so mount() restores the player at their saved progress.
 *
 * #3 Leave-confirm nav: ANY in-room member (ready or not) clicking an internal link AWAY
 *    from /multiplayer gets a confirmation OVERLAY (the <x-modal> in the lobby view, not
 *    the browser's confirm()). On Confirm we leave via the leave-confirm endpoint then
 *    navigate; Cancel stays in the room. A link that stays on /multiplayer isn't intercepted.
 *
 * Nav is a full page load (plain anchors), so this reads freshly-rendered data-* at
 * click/unload time rather than caching state.
 */

function flagsEl() {
    return document.querySelector('[data-mp-flags]');
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content;
}

/** POST with keepalive so it survives the page unload (mirrors the presence heartbeat). */
function leaveRequest(url) {
    const token = csrfToken();
    if (!url || !token) return;

    return fetch(url, {
        method: 'POST',
        headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
        keepalive: true,
    }).catch(() => {}); // swallow: nothing to retry on unload
}

export function registerMultiplayerNav() {
    if (window.__mpNavRegistered) return;
    window.__mpNavRegistered = true;

    // ---- #2: leave beacon on page unload ----
    const onLeave = () => {
        const el = flagsEl();
        if (!el) return; // not on /multiplayer
        // Only fire while genuinely in a waiting room; the server still re-checks
        // (ready/host are kept, so their row survives for mount() restore). Never during a
        // race: an unload there is a reload, and the row must survive to restore progress.
        if (el.dataset.mpInRoom === '1' && el.dataset.mpWaiting === '1') {
            leaveRequest(el.dataset.mpLeaveBeacon);
        }
    };
    // pagehide covers close/refresh/nav (incl. bfcache); beforeunload as a fallback.
    window.addEventListener('pagehide', onLeave);
    window.addEventListener('beforeunload', onLeave);

    // ---- #3: leave-confirm overlay interceptor on internal nav ----
    // The pending destination is stashed here so the overlay's Confirm button knows where
    // the user was headed.
    let pendingUrl = null;
    let pendingConfirmUrl = null;

    // Called by the overlay's Confirm button (see multiplayer-lobby.blade.php).
    window.__mpConfirmLeave = () => {
        if (!pendingUrl) return;
        const dest = pendingUrl;
        const req = leaveRequest(pendingConfirmUrl); // leave (host handoff handled server-side)
        Promise.resolve(req).finally(() => {
            window.location.href = dest;
        });
    };

    document.addEventListener(
        'click',
        (e) => {
            const el = flagsEl();
            if (!el || el.dataset.mpInRoom !== '1') return; // not in a room -> normal nav

            const link = e.target.closest('a[href]');
            if (!link) return;

            const href = link.getAttribute('href');
            if (!href || href.startsWith('#')) return;

            let dest;
            try {
                dest = new URL(link.href, window.location.origin);
            } catch {
                return;
            }

            if (dest.origin !== window.location.origin) return; // external -> leave alone
            if (dest.pathname === new URL(el.dataset.mpPage, window.location.origin).pathname) {
                return; // staying on /multiplayer -> no confirm
            }

            // Intercept: stash the destination and open the confirmation overlay instead
            // of navigating. Confirm -> __mpConfirmLeave(); Cancel -> modal just closes.
            e.preventDefault();
            pendingUrl = link.href;
            pendingConfirmUrl = el.dataset.mpLeaveConfirm;
            window.dispatchEvent(new CustomEvent('open-modal', { detail: 'confirm-leave-room' }));
        },
        true, // capture: beat other handlers
    );
}
