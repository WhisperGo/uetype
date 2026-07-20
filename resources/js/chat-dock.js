/**
 * Dock chat overlay: posisi bubble (draggable + clamp ke layar) & hide saat
 * sesi ketik/balapan aktif.
 *
 * State posisi disimpan di window, bukan di komponen, supaya bubble tak
 * "lompat" balik ke sudut tiap kali wire:navigate mengganti halaman.
 *
 * Dulu ini <script> inline di chat-overlay.blade.php dengan penjaga
 * __chatOverlayDockRegistered, karena script inline ikut dieksekusi ulang tiap
 * komponen dirender. Sebagai modul ia dievaluasi sekali per page load, jadi
 * penjaga itu tak lagi diperlukan.
 */

const BUBBLE = 56;   // ukuran tombol (w-14 h-14)
const MARGIN = 20;   // jarak minimum dari tepi layar (setara bottom-5/right-5)

export default function chatOverlayDock(open) {
    return {
        open,
        hidden: false,
        // Posisi disimpan sebagai JARAK ke tepi terdekat (bukan px absolut dari
        // sudut kiri-atas). ex/ey = jarak px ke tepi yang dipilih sideX/sideY.
        // Kebal zoom: saat innerWidth/innerHeight berubah, px absolut dihitung
        // ULANG dari jarak-tepi ini (lihat resolvePx), jadi bubble tetap menempel
        // di sudut/sisi yang sama. null = pakai default sudut kanan-bawah.
        anchor: window.__chatOverlayAnchor || null,
        // Dependensi reaktif buatan: resolvePx() membaca window.innerWidth/Height
        // (bukan state Alpine), jadi resize/zoom tak otomatis memicu re-evaluasi
        // :style. Menaikkan angka ini pada resize memaksa bubbleStyle/panelStyle
        // dihitung ulang.
        viewportTick: 0,

        init() {
            this.place();
            window.addEventListener('resize', () => this.reanchor());

            // Sembunyikan saat sesi ketik/balapan aktif; tutup drawer juga.
            window.addEventListener('test-activity', (e) => {
                this.hidden = !!(e.detail && e.detail.active);
                if (this.hidden) this.open = false;
            });
            // Ganti halaman: reset hide (sesi test halaman lama sudah berakhir).
            document.addEventListener('livewire:navigated', () => { this.hidden = false; });
        },

        // Default: sudut kanan-bawah dengan jarak MARGIN dari kedua tepi.
        place() {
            if (!this.anchor) {
                this.anchor = { ex: MARGIN, ey: MARGIN, sideX: 'right', sideY: 'bottom' };
            }
            this.reanchor();
        },

        // Terjemahkan jarak-tepi -> px absolut kiri-atas memakai viewport SAAT INI,
        // lalu clamp agar bubble tetap utuh. Dipanggil tiap render & tiap resize/zoom.
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

        // Hitung ulang posisi dari jarak-tepi setelah viewport berubah (resize/zoom):
        // naikkan viewportTick agar :style dievaluasi ulang, lalu persist anchor.
        reanchor() {
            this.viewportTick++;
            window.__chatOverlayAnchor = this.anchor;
        },

        // Ubah px absolut kiri-atas -> model jarak-tepi (pilih tepi terdekat pada
        // tiap sumbu). Dipakai saat drag selesai supaya posisi baru kebal zoom.
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

        // Satu-satunya penentu buka/tutup: keputusan diambil di pointerup, BUKAN
        // lewat event click sintetis (yang bisa balapan / tak konsisten antar
        // browser). Kalau selama gesture pointer bergeser >4px = drag (chat tak
        // di-toggle); kalau diam = tap (toggle). Jadi menggeser TIDAK PERNAH
        // membuka/menutup chat.
        startDrag(e) {
            // Hanya tombol kiri; abaikan klik kanan/tengah.
            if (e.button !== undefined && e.button !== 0) return;
            e.preventDefault();

            const btn = this.$refs.bubble;
            const startX = e.clientX, startY = e.clientY;
            const origin = this.resolvePx();
            let moved = false;
            let last = origin;

            // Pointer capture: semua pointermove/up dialihkan ke tombol ini,
            // meski kursor keluar dari tombol saat menggeser.
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
                // Selama gesture pakai anchor kiri-atas sementara (gerak halus).
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
                    // Drag selesai -> kunci ke tepi terdekat (kebal zoom) & persist.
                    this.anchor = this.pxToAnchor(last.x, last.y);
                    window.__chatOverlayAnchor = this.anchor;
                }
            };
            window.addEventListener('pointermove', move);
            window.addEventListener('pointerup', up);
        },

        // Object-form :style (bukan string) supaya Alpine MERGE properti posisi
        // dan tak menimpa `display` yang dikelola x-show — kalau string, x-show
        // yang menyembunyikan bubble/panel akan ter-clobber tiap :style re-run.
        bubbleStyle() {
            const p = this.resolvePx();
            return { left: p.x + 'px', top: p.y + 'px', right: 'auto', bottom: 'auto' };
        },

        // Drawer menempel ke bubble, lalu di-clamp agar tak keluar layar.
        // Buka ke atas kalau ruang di bawah kurang; geser kiri kalau mepet kanan.
        panelStyle() {
            const p = this.resolvePx();
            const gap = 12;
            const panel = this.$refs.panel;
            const pw = panel?.offsetWidth || Math.min(384, window.innerWidth - MARGIN * 2);
            const ph = panel?.offsetHeight || Math.min(512, window.innerHeight * 0.7);

            // Kanan-selaraskan drawer dengan bubble; clamp horizontal.
            let left = p.x + BUBBLE - pw;
            left = Math.max(MARGIN, Math.min(left, window.innerWidth - pw - MARGIN));

            // Default buka ke atas bubble; kalau tak muat, buka ke bawah.
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
