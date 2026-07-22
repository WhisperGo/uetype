/**
 * Chat runtime: optimistic bubbles, sending via fetch(), patching edits/deletes from the
 * WebSocket, and auto-scroll.
 *
 * One factory serves TWO components alive in the DOM at once when /chat is open: the full
 * page and the overlay drawer. Because they coexist, each variant MUST have its own
 * namespace -- container id, wire:key prefix, global function names, event names, and the
 * pending counter. Shared, one variant would drop the other's optimistic bubbles and
 * scroll the wrong container.
 *
 * DELIBERATELY not here: window.__chatOverlayState. That flag is read by toasts.js to
 * suppress toasts and may only be written by the overlay variant -- so it stays in
 * chat-overlay.blade.php's @script, not in this shared runtime.
 */

const VARIANTS = {
    page: {
        containerId: 'chat-messages',
        keyPrefix: 'msg',
        optPrefix: 'opt',
        pendingKey: '__chatPendingSends',
        hookKey: '__chatScrollHookRegistered',
        scrollEvent: 'chat-scrolled',
        alpineData: 'chatScroll',
        globals: {
            send: 'chatSend',
            appendOutgoing: 'chatAppendOutgoing',
            scrollTo: 'chatScrollToMessage',
        },
        // Bubble selector for edit/delete patching. Different radius per variant, so a
        // different selector -- the two are DELIBERATELY not unified so a patch never hits
        // the other variant's bubbles.
        bubbleSelector: '.rounded-2xl',
        cls: {
            wrap: 'max-w-[75%]',
            name: 'font-mono text-[0.65rem] text-muted mb-1 px-1',
            bubble: 'px-4 py-2.5 rounded-2xl font-mono text-sm break-words',
            time: 'text-[0.6rem] mt-1 opacity-60',
            deletedRow: 'flex items-center gap-1.5',
            deletedIcon: 'w-3.5 h-3.5 shrink-0',
        },
    },
    overlay: {
        containerId: 'overlay-chat-messages',
        keyPrefix: 'overlay-msg',
        optPrefix: 'overlay-opt',
        pendingKey: '__chatOverlayPendingSends',
        hookKey: '__chatOverlayScrollHookRegistered',
        scrollEvent: 'chat-overlay-scrolled',
        alpineData: 'chatOverlayScroll',
        globals: {
            send: 'chatOverlaySend',
            appendOutgoing: 'chatOverlayAppendOutgoing',
            scrollTo: 'chatOverlayScrollToMessage',
        },
        bubbleSelector: '.rounded-xl',
        cls: {
            wrap: 'max-w-[85%]',
            name: 'font-mono text-[0.6rem] text-muted mb-0.5 px-1',
            bubble: 'px-3 py-2 rounded-xl font-mono text-xs break-words',
            time: 'text-[0.55rem] mt-0.5 opacity-60',
            deletedRow: 'flex items-center gap-1',
            deletedIcon: 'w-3 h-3 shrink-0',
        },
    },
};

const DELETED_ICON_PATH =
    'M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636';

/** A WebSocket payload = another user's input; escape it before it enters the DOM. */
const esc = (s) => {
    const d = document.createElement('div');
    d.textContent = s ?? '';
    return d.innerHTML;
};

const clockLabel = () =>
    new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false });

/**
 * @param {object}   o
 * @param {'page'|'overlay'} o.variant
 * @param {object}   o.wire      the calling Livewire component's $wire
 * @param {number}   o.meId      auth()->id()
 * @param {string}   o.sendUrl   route('chat.send')
 * @param {object}   o.labels    { edited, deleted } from __() -- the runtime must not
 *                               call the translator itself
 * @param {Function} [o.isActive] true when this variant is visible to the user. The full
 *                               page is always true; the overlay only while the drawer is
 *                               open -- that's what stops the overlay from drawing bubbles
 *                               into a closed drawer.
 */
export default function createChatRuntime({ variant, wire, meId, sendUrl, labels, isActive }) {
    const v = VARIANTS[variant];
    const active = isActive ?? (() => true);

    const container = () => document.getElementById(v.containerId);
    const scrollToBottom = (list) => {
        requestAnimationFrame(() => {
            list.scrollTop = list.scrollHeight;
        });
    };

    // Does the incoming message belong to the currently-open conversation?
    const belongsToOpenConversation = (d) => {
        if (!active()) return false;
        if (d.kind === 'dm') {
            return wire.activeMode === 'dm' && wire.withUsername === d.senderUsername;
        }
        if (d.kind === 'clan') {
            return wire.activeMode === 'clan';
        }
        return false;
    };

    // Draw the bubble straight from the WS payload without waiting for a server render.
    // The wire:key matches the later Livewire render -> no duplication.
    const appendBubble = (d) => {
        const list = container();
        if (!list) return;
        if (document.querySelector(`[wire\\:key="${v.keyPrefix}-${d.messageId}"]`)) return; // already there

        const showName = d.kind === 'clan' && d.senderId !== meId;
        const wrap = document.createElement('div');
        wrap.className = 'flex justify-start';
        wrap.setAttribute('wire:key', `${v.keyPrefix}-${d.messageId}`);
        wrap.innerHTML =
            `<div class="${v.cls.wrap} flex flex-col items-start">` +
                (showName ? `<p class="${v.cls.name}">${esc(d.senderUsername)}</p>` : '') +
                `<div class="${v.cls.bubble} bg-white/5 text-foreground rounded-bl-md">` +
                    esc(d.body) +
                    `<p class="${v.cls.time}">` + clockLabel() + '</p>' +
                '</div>' +
            '</div>';
        list.appendChild(wrap);
        scrollToBottom(list);
    };

    // Your own bubble shows instantly on submit (supports rapid-fire sends). A unique
    // "opt-N" wire:key keeps Livewire morph from touching it (no flicker); they're dropped
    // together once all sends finish, replaced by the real bubbles.
    let optSeq = 0;
    // Counter on window so the global morph.updated hook still reads the right value after
    // wire:navigate.
    window[v.pendingKey] = window[v.pendingKey] || 0;

    const appendOutgoing = (body) => {
        const list = container();
        if (!list) return;
        const wrap = document.createElement('div');
        wrap.className = 'flex justify-end';
        wrap.setAttribute('data-optimistic', '1');
        wrap.setAttribute('wire:key', `${v.optPrefix}-${++optSeq}`);
        wrap.innerHTML =
            `<div class="${v.cls.wrap}">` +
                `<div class="${v.cls.bubble} bg-gold text-background rounded-br-md opacity-70">` +
                    esc(body) +
                    `<p class="${v.cls.time}">` + clockLabel() + '</p>' +
                '</div>' +
            '</div>';
        list.appendChild(wrap);
        scrollToBottom(list);
    };

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
    let syncTimer = null;

    // Send via parallel fetch() (not Livewire) so rapid sends don't wait on each other.
    // Once all sends finish, sync to the server once (debounced).
    const send = (body) => {
        const mode = wire.activeMode;
        if (!mode) return;

        appendOutgoing(body);

        // Reply only applies to the first send since the preview was opened.
        const replyId = wire.replyingToId || null;
        if (replyId) wire.cancelReply();

        window[v.pendingKey]++;
        fetch(sendUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf,
                Accept: 'application/json',
            },
            body: JSON.stringify({
                mode,
                body,
                with: wire.withUsername || null,
                reply_to_id: replyId,
            }),
        })
            .catch(() => {})
            .finally(() => {
                window[v.pendingKey]--;
                if (window[v.pendingKey] === 0) {
                    // Just ask for a re-render (debounced). Optimistic bubbles are dropped
                    // in morph.updated, after the real bubbles are in the DOM, so a message
                    // never disappears and reappears.
                    clearTimeout(syncTimer);
                    syncTimer = setTimeout(() => wire.dispatch('message-received'), 120);
                }
            });
    };

    // Click a reply quote -> scroll to the original message & flash it briefly.
    const scrollToMessage = (id) => {
        const el = document.querySelector(`[wire\\:key="${v.keyPrefix}-${id}"]`);
        if (!el) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.classList.add('chat-flash');
        setTimeout(() => el.classList.remove('chat-flash'), 1200);
    };

    // Edit/delete from the other side: patch the bubble straight from the payload
    // (instant); the server is still synced in the background.
    const patchMutation = (d) => {
        const node = document.querySelector(`[wire\\:key="${v.keyPrefix}-${d.messageId}"]`);
        if (!node) return false;
        const bubble = node.querySelector(v.bubbleSelector);
        if (!bubble) return false;

        if (d.action === 'edited') {
            // Rebuild the content: drop everything except the time <p>, insert the new text.
            const timeP = bubble.querySelector('p.opacity-60');
            [...bubble.childNodes].forEach((n) => {
                if (n !== timeP) n.remove();
            });
            bubble.insertBefore(document.createTextNode(d.body ?? ''), timeP);
            if (timeP && !timeP.dataset.edited) {
                timeP.append(` · ${labels.edited}`);
                timeP.dataset.edited = '1';
            }
        } else if (d.action === 'deleted') {
            bubble.classList.add('opacity-60', 'italic');
            bubble.innerHTML =
                `<span class="${v.cls.deletedRow}">` +
                `<svg class="${v.cls.deletedIcon}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="${DELETED_ICON_PATH}"/></svg>` +
                esc(labels.deleted) +
                '</span>';
        }
        return true;
    };

    const onRemote = (ev) => {
        const d = ev.detail;
        if (d && belongsToOpenConversation(d)) {
            appendBubble(d);
        }
        // Still sync the authoritative state (read receipt, dedup, etc.) -- but only if
        // this variant is visible, so a closed drawer doesn't trigger a Livewire round-trip
        // on every incoming message.
        if (active()) {
            wire.dispatch('message-received');
        }
    };

    const onMutated = (ev) => {
        const d = ev.detail;
        if (!active()) return;
        if (d && d.messageId) patchMutation(d);
        // Server sync (e.g. for a not-yet-loaded message / reply quote).
        wire.dispatch('message-received');
    };

    window.addEventListener('message-received-remote', onRemote);
    window.addEventListener('message-mutated-remote', onMutated);

    document.addEventListener(
        'livewire:navigating',
        () => {
            window.removeEventListener('message-received-remote', onRemote);
            window.removeEventListener('message-mutated-remote', onMutated);
        },
        { once: true }
    );

    // Auto-scroll to the newest message whenever the list changes. The hook is registered
    // once globally so it doesn't stack up each time a window opens/closes.
    if (!window[v.hookKey]) {
        window[v.hookKey] = true;
        window.Livewire.hook('morph.updated', () => {
            // The real bubbles are in the DOM now -> only then drop the optimistic placeholders.
            if (window[v.pendingKey] === 0) {
                document
                    .querySelectorAll(`#${v.containerId} [data-optimistic]`)
                    .forEach((n) => n.remove());
            }
            const el = container();
            if (el) scrollToBottom(el);
        });
    }

    window.Alpine.data(v.alpineData, () => ({
        init() {
            this.$nextTick(() => {
                const el = container();
                if (!el) return;
                el.scrollTop = el.scrollHeight;

                // Close any open action menu when the list is scrolled, so its position
                // (computed once when opened) doesn't go stale.
                el.addEventListener(
                    'scroll',
                    () => {
                        window.dispatchEvent(new CustomEvent(v.scrollEvent));
                    },
                    { passive: true }
                );
            });
        },
    }));

    // The markup calls these via onclick=/@submit, so they must live on window under a
    // name that's distinct per variant.
    window[v.globals.send] = send;
    window[v.globals.appendOutgoing] = appendOutgoing;
    window[v.globals.scrollTo] = scrollToMessage;
}
