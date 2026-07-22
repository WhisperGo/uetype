/**
 * Chat overlay dock: bubble positioning (draggable + clamped to the screen) & hiding
 * while a typing/racing session is active.
 *
 * Position state is stored on window, not in the component, so the bubble doesn't "jump"
 * back to the corner each time wire:navigate swaps the page.
 *
 * This used to be an inline <script> in chat-overlay.blade.php guarded by
 * __chatOverlayDockRegistered, because inline scripts re-run on every component render.
 * As a module it's evaluated once per page load, so that guard is no longer needed.
 */

const BUBBLE = 56;   // button size (w-14 h-14)
const MARGIN = 20;   // minimum gap from the screen edge (matches bottom-5/right-5)

export default function chatOverlayDock(open) {
    return {
        open,
        hidden: false,
        // Position stored as the DISTANCE to the nearest edge (not absolute px from the
        // top-left corner). ex/ey = px distance to the chosen sideX/sideY edge. Zoom-proof:
        // when innerWidth/innerHeight changes, absolute px is RECOMPUTED from this edge
        // distance (see resolvePx), so the bubble stays pinned to the same corner/side.
        // null = use the default bottom-right corner.
        anchor: window.__chatOverlayAnchor || null,
        // A synthetic reactive dependency: resolvePx() reads window.innerWidth/Height (not
        // Alpine state), so resize/zoom doesn't automatically re-evaluate :style. Bumping
        // this on resize forces bubbleStyle/panelStyle to recompute.
        viewportTick: 0,

        init() {
            this.place();
            window.addEventListener('resize', () => this.reanchor());

            // Hide while a typing/racing session is active; also close the drawer.
            window.addEventListener('test-activity', (e) => {
                this.hidden = !!(e.detail && e.detail.active);
                if (this.hidden) this.open = false;
            });
            // Page change: reset hide (the old page's test session has ended).
            document.addEventListener('livewire:navigated', () => { this.hidden = false; });
        },

        // Default: bottom-right corner, MARGIN away from both edges.
        place() {
            if (!this.anchor) {
                this.anchor = { ex: MARGIN, ey: MARGIN, sideX: 'right', sideY: 'bottom' };
            }
            this.reanchor();
        },

        // Translate edge distance -> absolute top-left px using the CURRENT viewport, then
        // clamp so the bubble stays whole. Called on every render & every resize/zoom.
        resolvePx() {
            void this.viewportTick;
            const a = this.anchor || { ex: MARGIN, ey: MARGIN, sideX: 'right', sideY: 'bottom' };
            const maxX = Math.max(MARGIN, window.innerWidth - BUBBLE - MARGIN);
            const maxY = Math.max(MARGIN, window.innerHeight - BUBBLE - MARGIN);

            let x = a.sideX === 'right' ? window.innerWidth - BUBBLE - a.ex : a.ex;
            let y = a.sideY === 'bottom' ? window.innerHeight - BUBBLE - a.ey : a.ey;

            x = Math.max(MARGIN, Math.min(x, maxX));
            y = Math.max(MARGIN, Math.min(y, maxY));
            return { x, y };
        },

        // Recompute position from edge distance after the viewport changes (resize/zoom):
        // bump viewportTick so :style re-evaluates, then persist the anchor.
        reanchor() {
            this.viewportTick++;
            window.__chatOverlayAnchor = this.anchor;
        },

        // Convert absolute top-left px -> the edge-distance model (pick the nearest edge on
        // each axis). Used when a drag ends so the new position is zoom-proof.
        pxToAnchor(x, y) {
            const rightGap = window.innerWidth - BUBBLE - x;
            const bottomGap = window.innerHeight - BUBBLE - y;
            const sideX = x <= rightGap ? 'left' : 'right';
            const sideY = y <= bottomGap ? 'top' : 'bottom';
            return {
                ex: Math.max(MARGIN, sideX === 'left' ? x : rightGap),
                ey: Math.max(MARGIN, sideY === 'top' ? y : bottomGap),
                sideX,
                sideY,
            };
        },

        // The sole open/close decider: the decision is made on pointerup, NOT via a
        // synthetic click event (which can race / be inconsistent across browsers). If the
        // pointer moves >4px during the gesture = drag (chat isn't toggled); if it stays
        // put = tap (toggle). So dragging NEVER opens/closes the chat.
        startDrag(e) {
            // Left button only; ignore right/middle click.
            if (e.button !== undefined && e.button !== 0) return;
            e.preventDefault();

            const btn = this.$refs.bubble;
            const startX = e.clientX, startY = e.clientY;
            const origin = this.resolvePx();
            let moved = false;
            let last = origin;

            // Pointer capture: all pointermove/up are routed to this button, even if the
            // cursor leaves the button while dragging.
            try { btn.setPointerCapture(e.pointerId); } catch (_) {}

            const move = (ev) => {
                const dx = ev.clientX - startX;
                const dy = ev.clientY - startY;
                if (!moved && (Math.abs(dx) > 4 || Math.abs(dy) > 4)) moved = true;
                if (!moved) return;
                const maxX = Math.max(MARGIN, window.innerWidth - BUBBLE - MARGIN);
                const maxY = Math.max(MARGIN, window.innerHeight - BUBBLE - MARGIN);
                const x = Math.max(MARGIN, Math.min(origin.x + dx, maxX));
                const y = Math.max(MARGIN, Math.min(origin.y + dy, maxY));
                last = { x, y };
                // During the gesture use a temporary top-left anchor (smooth movement).
                this.anchor = { ex: x, ey: y, sideX: 'left', sideY: 'top' };
            };
            const up = (ev) => {
                window.removeEventListener('pointermove', move);
                window.removeEventListener('pointerup', up);
                try { btn.releasePointerCapture(ev.pointerId); } catch (_) {}
                if (!moved) {
                    // Tap -> toggle chat.
                    this.open = ! this.open;
                } else {
                    // Drag done -> lock to the nearest edge (zoom-proof) & persist.
                    this.anchor = this.pxToAnchor(last.x, last.y);
                    window.__chatOverlayAnchor = this.anchor;
                }
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        // Object-form :style (not a string) so Alpine MERGES the position properties and
        // doesn't overwrite the `display` managed by x-show — with a string, the x-show that
        // hides the bubble/panel would get clobbered on every :style re-run.
        bubbleStyle() {
            const p = this.resolvePx();
            return { left: p.x + 'px', top: p.y + 'px', right: 'auto', bottom: 'auto' };
        },

        // The drawer sticks to the bubble, then is clamped so it stays on-screen. Opens
        // upward if there isn't room below; shifts left if it's tight against the right.
        panelStyle() {
            const p = this.resolvePx();
            const gap = 12;
            const panel = this.$refs.panel;
            const pw = panel?.offsetWidth || Math.min(384, window.innerWidth - MARGIN * 2);
            const ph = panel?.offsetHeight || Math.min(512, window.innerHeight * 0.7);

            // Right-align the drawer with the bubble; clamp horizontally.
            let left = p.x + BUBBLE - pw;
            left = Math.max(MARGIN, Math.min(left, window.innerWidth - pw - MARGIN));

            // Default to opening above the bubble; if it doesn't fit, open below.
            let top = p.y - gap - ph;
            if (top < MARGIN) {
                const below = p.y + BUBBLE + gap;
                top = (below + ph <= window.innerHeight - MARGIN) ? below : MARGIN;
            }
            top = Math.max(MARGIN, Math.min(top, window.innerHeight - ph - MARGIN));

            return { left: left + 'px', top: top + 'px', right: 'auto', bottom: 'auto' };
        },
    };
}
