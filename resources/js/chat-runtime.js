/**
 * Runtime chat: bubble optimistic, kirim lewat fetch(), patch edit/hapus dari
 * WebSocket, dan auto-scroll.
 *
 * Satu factory dipakai DUA komponen yang hidup bersamaan di DOM saat /chat
 * dibuka: halaman penuh dan overlay drawer. Karena keduanya bersamaan, tiap
 * varian WAJIB punya namespace sendiri -- id container, prefix wire:key, nama
 * fungsi global, nama event, dan counter pending. Kalau dipakai bersama, satu
 * varian akan membuang bubble optimistic milik varian lain dan menggulir
 * container yang salah.
 *
 * Yang SENGAJA tidak ada di sini: window.__chatOverlayState. Flag itu dibaca
 * toasts.js untuk mensupresi toast, dan hanya boleh ditulis varian overlay --
 * jadi ia tetap tinggal di @script chat-overlay.blade.php, bukan di runtime
 * bersama ini.
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
        // Selector bubble untuk patch edit/hapus. Beda radius per varian, jadi
        // beda selector -- keduanya sengaja TIDAK diseragamkan supaya patch
        // tak pernah mengenai bubble milik varian lain.
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

/** Payload WebSocket = input user lain; escape sebelum masuk DOM. */
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
 * @param {object}   o.wire      $wire komponen Livewire pemanggil
 * @param {number}   o.meId      auth()->id()
 * @param {string}   o.sendUrl   route('chat.send')
 * @param {object}   o.labels    { edited, deleted } hasil __() -- runtime tak
 *                               boleh memanggil translator sendiri
 * @param {Function} [o.isActive] true kalau varian ini sedang terlihat user.
 *                               Halaman penuh selalu true; overlay hanya saat
 *                               drawer terbuka -- itu yang mencegah overlay
 *                               menggambar bubble ke drawer yang tertutup.
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

    // Apakah pesan masuk termasuk percakapan yang sedang dibuka?
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

    // Gambar bubble langsung dari payload WS tanpa menunggu render server.
    // wire:key sama dengan render Livewire nanti -> tak terduplikasi.
    const appendBubble = (d) => {
        const list = container();
        if (!list) return;
        if (document.querySelector(`[wire\\:key="${v.keyPrefix}-${d.messageId}"]`)) return; // sudah ada

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

    // Bubble pesan sendiri tampil seketika saat submit (mendukung spam beruntun).
    // wire:key unik "opt-N" agar Livewire morph tak menyentuhnya (tak kedip);
    // dibuang serentak saat semua kiriman selesai, digantikan bubble asli.
    let optSeq = 0;
    // Counter di window agar hook morph.updated global tetap membaca nilai yang
    // benar setelah wire:navigate.
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

    // Kirim via fetch() paralel (bukan Livewire) agar spam tak saling menunggu.
    // Setelah semua kiriman selesai, sinkron sekali (debounced) ke server.
    const send = (body) => {
        const mode = wire.activeMode;
        if (!mode) return;

        appendOutgoing(body);

        // Reply hanya berlaku untuk kiriman pertama sejak preview dibuka.
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
                    // Hanya minta re-render (debounced). Bubble optimistic dibuang
                    // di morph.updated, setelah bubble asli ada di DOM, agar pesan
                    // tak sempat hilang lalu muncul lagi.
                    clearTimeout(syncTimer);
                    syncTimer = setTimeout(() => wire.dispatch('message-received'), 120);
                }
            });
    };

    // Klik kutipan reply -> gulir ke pesan asli & kedipkan sebentar.
    const scrollToMessage = (id) => {
        const el = document.querySelector(`[wire\\:key="${v.keyPrefix}-${id}"]`);
        if (!el) return;
        el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        el.classList.add('chat-flash');
        setTimeout(() => el.classList.remove('chat-flash'), 1200);
    };

    // Edit/hapus dari sisi lain: patch bubble langsung dari payload (instan),
    // server tetap disinkronkan di belakang layar.
    const patchMutation = (d) => {
        const node = document.querySelector(`[wire\\:key="${v.keyPrefix}-${d.messageId}"]`);
        if (!node) return false;
        const bubble = node.querySelector(v.bubbleSelector);
        if (!bubble) return false;

        if (d.action === 'edited') {
            // Bangun ulang isi: buang semua kecuali <p> waktu, sisipkan teks baru.
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
        // Tetap sinkronkan state otoritatif (read receipt, dedup, dsb) -- tapi
        // hanya kalau varian ini terlihat, supaya drawer yang tertutup tak
        // memicu roundtrip Livewire tiap pesan masuk.
        if (active()) {
            wire.dispatch('message-received');
        }
    };

    const onMutated = (ev) => {
        const d = ev.detail;
        if (!active()) return;
        if (d && d.messageId) patchMutation(d);
        // Sinkron server (mis. untuk pesan yang belum termuat / kutipan reply).
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

    // Auto-scroll ke pesan terbaru tiap daftar berubah. Hook didaftarkan sekali
    // secara global agar tak menumpuk tiap jendela dibuka/tutup.
    if (!window[v.hookKey]) {
        window[v.hookKey] = true;
        window.Livewire.hook('morph.updated', () => {
            // Bubble asli sudah ada di DOM -> baru buang placeholder optimistic.
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

                // Tutup menu aksi yang terbuka saat daftar di-scroll, supaya
                // posisinya (yang dihitung sekali saat dibuka) tak jadi basi.
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

    // Markup memanggil fungsi ini lewat onclick=/@submit, jadi keduanya harus
    // ada di window dengan nama yang khas per varian.
    window[v.globals.send] = send;
    window[v.globals.appendOutgoing] = appendOutgoing;
    window[v.globals.scrollTo] = scrollToMessage;
}
