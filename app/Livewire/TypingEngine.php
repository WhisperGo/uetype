<?php

namespace App\Livewire;

use App\Enums\ClanWarStatus;
use App\Models\ClanWarFixedText;
use App\Models\ClanWarModeClaim;
use App\Models\TypingResult;
use App\Services\AchievementService;
use App\Services\AntiCheatService;
use App\Services\ClanWarScorer;
use App\Services\GhostResolver;
use App\Services\TextGeneratorService;
use App\Services\TypingErrorInspector;
use App\Support\TypingLanguage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The core solo typing arena: picks the text, receives the finished session, and
 * persists it. WPM/accuracy are recomputed server-side (AntiCheatService), never
 * trusted from the client, then feed personal bests, XP, and achievements.
 */
#[Layout('layouts.app')]
class TypingEngine extends Component
{
    // Mode Utama: 'time', 'words', 'survival'
    public $mainMode = 'time';

    // Sub Mode (Pilihan angka/panjang)
    public $subMode = '30';

    // Whitelist sub-mode sah per mode utama; gerbang server-side karena mainMode/subMode
    // dikendalikan client dan mode_config masuk kunci filter leaderboard.
    private const ALLOWED_SUBMODES = [
        'time' => ['15', '30', '60', '120'],
        'words' => ['10', '25', '50', '100'],
        'survival' => ['easy', 'medium', 'hard'],
    ];

    public $textToType;

    // Teks "Retry" (mode words) untuk request ini saja: di-pull dari session di mount(),
    // dipakai generateText() menggantikan perakitan acak. Properti privat (bukan public
    // Livewire) supaya tak dipersistensi antar-request -- retry berikutnya via restart()
    // tetap menghasilkan teks acak, tak lengket.
    private ?string $retryText = null;

    public string $contentLang = TypingLanguage::DEFAULT;

    public int $typingSessionKey = 0;

    // Penanda ghost aktif; sumber kebenaran server. Ghost hanya sah untuk time/words -
    // pindah ke survival memaksa false (invariant, bukan sekadar event klien).
    public bool $ghostActive = false;

    // Clan War: id ClanWarModeClaim yang dikerjakan (dari ?war_claim=). Kalau valid, mode
    // dikunci ke mode/config klaim & hasil ketik otomatis jadi war attempt. Null = sesi solo.
    #[Url(as: 'war_claim')]
    public ?int $warClaimId = null;

    // Detail mode klaim war (mode+config) untuk banner view. Null kalau tidak war-lock.
    public ?array $warLock = null;

    // Ghost deep-link dari leaderboard: ?ghost=<user_id>&mode=<time|words>&config=<sub>.
    // WPM lawan diturunkan ulang dari DB (bukan dari client), sejalan dgn GhostPicker.
    #[Url(as: 'ghost')]
    public ?int $ghostUserId = null;

    #[Url(as: 'mode')]
    public ?string $ghostMode = null;

    #[Url(as: 'config')]
    public ?string $ghostConfig = null;

    public function mount()
    {
        // War-lock dicek dulu: valid -> mode dipaksa ke klaim; tak valid -> reset ke
        // null dan berperilaku sesi solo biasa (fail-safe).
        $claim = $this->resolveWarClaim();

        if ($claim) {
            [$this->mainMode, $this->subMode] = $this->normalizeMode($claim->mode, $claim->mode_config);
            $this->warLock = ['mode' => $this->mainMode, 'config' => $this->subMode];
        } else {
            $this->warClaimId = null;

            // Pulihkan preferensi sebelumnya jika ada (hanya untuk sesi solo biasa).
            // Lewat normalizeMode supaya nilai liar / survival-untuk-guest ikut ditolak.
            if (session()->has('typing_preferences')) {
                $prefs = session('typing_preferences');
                [$this->mainMode, $this->subMode] = $this->normalizeMode($prefs['mode'] ?? 'time', $prefs['subMode'] ?? '30');
            }
        }

        // Bahasa konten dipulihkan terpisah: war hanya mengunci mode/config, bukan bahasa.
        if (session()->has('typing_preferences')) {
            $this->contentLang = TypingLanguage::resolve(session('typing_preferences')['contentLang'] ?? null);
        }

        // Ghost diproses setelah war-lock supaya war tetap menang; hanya berlaku untuk
        // sesi solo & mode time/words. Deep-link (?ghost=) dicerna jadi pilihan tersimpan
        // lebih dulu, lalu applyGhostRestore memulihkan pilihan itu (atau yang sudah ada
        // di session dari tes sebelumnya -- inilah yang membuat ghost bertahan lintas tes).
        if (! $this->warLock) {
            $this->ingestGhostDeepLink();
            $this->applyGhostRestore();
        }

        // Retry (mode words): pull sekali pakai. Hanya jalur solo -- war-lock menang,
        // sama seperti ghost deep-link. Mode/config diikutkan supaya "ulang yang tadi"
        // benar-benar sama, bukan preferensi terakhir.
        if (! $this->warLock) {
            $this->applyRetryFromSession();
        }

        $this->generateText();
    }

    /**
     * Pasang teks retry dari session (sekali pakai) bila valid: mode words + teks ada.
     * generateText() lalu memakai $retryText ketimbang merakit teks acak baru.
     */
    private function applyRetryFromSession(): void
    {
        $retry = session()->pull('typing_retry');

        if (! is_array($retry) || ($retry['mode'] ?? null) !== 'words' || empty($retry['text'])) {
            return;
        }

        $this->mainMode = 'words';
        $this->subMode = (string) ($retry['subMode'] ?? $this->subMode);
        $this->retryText = (string) $retry['text'];
    }

    /**
     * Cerna deep-link ?ghost=&mode=&config= (dari baris leaderboard) menjadi PILIHAN
     * ghost tersimpan di session -- supaya ikut sticky seperti pilihan dari picker.
     * Mengunci mode/config ke milik ghost. Hanya time/words; param liar / lawan tanpa
     * rekor diabaikan (fail-safe). Dispatch-nya diserahkan ke applyGhostRestore().
     */
    private function ingestGhostDeepLink(): void
    {
        if (! $this->ghostUserId || ! Auth::check()
            || ! in_array($this->ghostMode, ['time', 'words'], true)) {
            $this->clearGhostDeepLinkParams();

            return;
        }

        [$main, $sub] = $this->normalizeMode($this->ghostMode, $this->ghostConfig);

        // Validasi lawan benar-benar punya rekor di mode/config itu sebelum menyimpan.
        $ghost = app(GhostResolver::class)->resolve('leaderboard', $this->ghostUserId, $main, $sub, Auth::id());

        if ($ghost === null) {
            $this->clearGhostDeepLinkParams();

            return;
        }

        $this->mainMode = $main;
        $this->subMode = $sub;

        session()->put('ghost_selection', ['type' => 'leaderboard', 'ref_id' => $this->ghostUserId]);

        $this->clearGhostDeepLinkParams();
    }

    /**
     * Pulihkan ghost dari pilihan tersimpan di session untuk mode/config SAAT INI.
     * Ini yang membuat ghost bertahan lintas Next Test / refresh: pilihan tetap di
     * session, dan tiap mount/pindah-mode ke time-words kita turunkan ULANG WPM dari
     * DB (bukan angka beku) lalu tampilkan.
     *
     * Mode non-eligible (survival) -> ghost cuma DISEMBUNYIKAN (ghostActive false),
     * pilihan di session TIDAK dihapus -> otomatis muncul lagi saat balik ke time/words.
     */
    private function applyGhostRestore(): void
    {
        // Ghost dikunci untuk guest: leaderboard ditutup untuk tamu, jadi ghost tak
        // boleh bocor lewat pintu ini -- bahkan bila ghost_selection dipalsukan.
        if (! Auth::check()) {
            $this->ghostActive = false;

            return;
        }

        if (! $this->isGhostEligibleMode()) {
            $this->ghostActive = false;

            return;
        }

        $selection = session('ghost_selection');
        if (! is_array($selection) || empty($selection['type'])) {
            return;
        }

        $ghost = app(GhostResolver::class)->resolve(
            $selection['type'],
            $selection['ref_id'] ?? null,
            $this->mainMode,
            $this->subMode,
            Auth::id(),
        );

        // Lawan tak lagi punya rekor di config ini -> sembunyikan (pilihan tetap disimpan).
        if ($ghost === null) {
            $this->ghostActive = false;

            return;
        }

        $this->ghostActive = true;
        $this->dispatch('ghost-selected', type: $ghost['type'], wpm: $ghost['wpm'], label: $ghost['label']);
    }

    /**
     * Clear eksplisit (tombol "Clear" di arena): buang pilihan dari session supaya
     * ghost TIDAK muncul lagi di tes berikutnya. Beda dari suspend saat survival, yang
     * cuma menyembunyikan tanpa menghapus.
     */
    public function clearGhost(): void
    {
        session()->forget('ghost_selection');
        $this->ghostActive = false;
        $this->dispatch('ghost-cleared');
    }

    /** Ghost Mode hanya sah untuk time & words (survival dikecualikan). */
    private function isGhostEligibleMode(): bool
    {
        return in_array($this->mainMode, ['time', 'words'], true);
    }

    /** Sinkronkan state ghost server saat klien memilih/melepas ghost. */
    #[On('ghost-selected')]
    public function onGhostSelected(): void
    {
        $this->ghostActive = $this->isGhostEligibleMode();
    }

    // Sembunyikan kursor ghost (suspend saat survival ATAU clear eksplisit). SENGAJA
    // tidak menyentuh session: penghapusan pilihan hanya lewat clearGhost()/clearOpponent().
    #[On('ghost-cleared')]
    public function onGhostCleared(): void
    {
        $this->ghostActive = false;
    }

    /** Kosongkan param ghost dari URL/state setelah diproses. */
    private function clearGhostDeepLinkParams(): void
    {
        $this->ghostUserId = null;
        $this->ghostMode = null;
        $this->ghostConfig = null;
    }

    /**
     * Ambil & validasi ClanWarModeClaim dari $warClaimId: harus milik clan aktif user,
     * war Ongoing, belum disubmit. Null kalau tak valid (tak melempar error, diabaikan saja).
     */
    private function resolveWarClaim(): ?ClanWarModeClaim
    {
        if (! $this->warClaimId || ! Auth::check()) {
            return null;
        }

        $claim = ClanWarModeClaim::with('war')->find($this->warClaimId);

        if (! $claim || $claim->isSubmitted()) {
            return null;
        }

        if (! $claim->war || $claim->war->status !== ClanWarStatus::Ongoing) {
            return null;
        }

        // Klaim harus milik clan aktif user ini.
        $myClan = Auth::user()->clan;
        if (! $myClan || $myClan->id !== $claim->clan_id) {
            return null;
        }

        return $claim;
    }

    /**
     * Tautkan hasil ketik ke klaim war (re-validasi ulang, tak percaya $warClaimId dari
     * client). Update bersyarat `whereNull('typing_result_id')` mencegah dua submit
     * paralel mengisi klaim yang sama. Dipanggil dari dalam DB::transaction saveResult().
     */
    private function attachToWarClaim(TypingResult $typingResult): void
    {
        $claim = $this->resolveWarClaim();

        if (! $claim) {
            return;
        }

        $points = ClanWarScorer::score($claim->mode, $claim->mode_config, $typingResult);

        // Update bersyarat: hanya isi kalau belum tersubmit (idempoten, race-safe).
        ClanWarModeClaim::where('id', $claim->id)
            ->whereNull('typing_result_id')
            ->update([
                'typing_result_id' => $typingResult->id,
                'user_id' => Auth::id(),
                'points' => $points,
            ]);
    }

    // Validasi mode+sub-mode terhadap whitelist, fallback ke default aman jika liar.
    // Satu sumber kebenaran dipakai setMode & saveResult supaya nilai client tak lolos ke DB.
    private function normalizeMode($main, $sub): array
    {
        if (! array_key_exists($main, self::ALLOWED_SUBMODES)) {
            return ['time', '30'];
        }

        // Survival dikunci untuk guest (butuh login): normalisasi ke Standard default.
        // Choke point tunggal -> setMode & restore preferensi sama-sama tertutup.
        if ($main === 'survival' && ! Auth::check()) {
            return ['time', '30'];
        }

        $allowed = self::ALLOWED_SUBMODES[$main];

        $sub = (string) $sub;
        if (! in_array($sub, $allowed, true)) {
            $sub = $allowed[0]; // default aman pertama
        }

        return [$main, $sub];
    }

    // Ganti mode dan ambil teks baru
    public function setMode($main, $sub)
    {
        // War-lock: mode tak boleh diganti saat mengerjakan war attempt (gerbang server-side).
        if ($this->warClaimId !== null && $this->resolveWarClaim()) {
            return;
        }

        [$main, $sub] = $this->normalizeMode($main, $sub);

        $this->mainMode = $main;
        $this->subMode = $sub ?? 'medium';

        // Simpan preferensi pengguna ke session agar tidak reset
        session()->put('typing_preferences', [
            'mode' => $main,
            'subMode' => $sub,
            'contentLang' => $this->contentLang,
        ]);
        session()->save();

        $this->generateText();

        if (! $this->isGhostEligibleMode()) {
            // Survival: sembunyikan kursor ghost (suspend). Pilihan di session SENGAJA
            // tak dihapus -> otomatis pulih saat balik ke time/words.
            $this->ghostActive = false;
            $this->dispatch('ghost-cleared');
        } else {
            // time/words: pulihkan ghost tersimpan, di-derive ulang utk mode/config baru.
            $this->applyGhostRestore();
        }

        $this->dispatch(
            'mode-changed',
            text: $this->textToType,
            main: $this->mainMode,
            sub: $this->subMode
        );
    }

    // Ganti bahasa konten yang diketik, terpisah dari bahasa UI.
    public function setContentLang($lang)
    {
        // War-lock: teks tak boleh di-reroll saat war attempt. restart() sudah menutup
        // jalur "refresh sampai dapat kata pendek", tapi generateText() juga terpanggil
        // dari sini -- tanpa gerbang ini pemain tinggal bolak-balik ganti bahasa untuk
        // mengacak ulang teksnya, celah yang sama persis lewat pintu lain. Sekali klaim,
        // satu teks, satu kesempatan.
        if ($this->warClaimId !== null && $this->resolveWarClaim()) {
            return;
        }

        $this->contentLang = TypingLanguage::resolve($lang);

        session()->put('typing_preferences', [
            'mode' => $this->mainMode,
            'subMode' => $this->subMode,
            'contentLang' => $this->contentLang,
        ]);
        session()->save();

        $this->generateText();

        // Teks baru dirakit -> posisi kursor ghost ikut reset; pulihkan ghost tersimpan
        // supaya tetap tampil (self-guard: no-op kalau mode survival / tak ada pilihan).
        $this->applyGhostRestore();

        $this->dispatch(
            'mode-changed',
            text: $this->textToType,
            main: $this->mainMode,
            sub: $this->subMode
        );
    }

    public function restart()
    {
        // War-lock: teks tak boleh di-reroll saat war attempt (menutup celah "refresh
        // sampai dapat kata pendek"); gerbang server-side, sekali klaim satu kesempatan.
        if ($this->warClaimId !== null && $this->resolveWarClaim()) {
            return;
        }

        $this->generateText();

        $this->dispatch(
            'mode-changed',
            text: $this->textToType,
            main: $this->mainMode,
            sub: $this->subMode
        );
    }

    public function generateText()
    {
        $this->typingSessionKey++;

        // Retry (mode words): pakai teks sesi sebelumnya yang identik, bukan dirakit acak.
        // Hanya di-set di mount() untuk jalur solo (war-lock menang), dan hanya sekali --
        // restart()/setMode() memanggil generateText() dengan $retryText sudah null lagi.
        if ($this->retryText !== null) {
            $this->textToType = $this->retryText;
            $this->retryText = null;

            return;
        }

        // Clan War mode Words pakai teks TETAP (identik untuk semua pemain di config yang
        // sama) demi keadilan, bukan dirakit acak. Time/Survival war cukup restart-nya
        // yang diblokir.
        if ($this->warClaimId !== null && $this->resolveWarClaim()) {
            if ($this->mainMode === 'words') {
                $fixed = ClanWarFixedText::forWords($this->subMode);

                // Fallback ke generate biasa kalau baris tetap tak ada (layar tak pernah kosong).
                if ($fixed !== null) {
                    $this->textToType = $fixed;

                    return;
                }
            }
        }

        // Time/words/survival: teks dirakit acak dari wordlist sesuai bahasa konten.
        $this->textToType = app(TextGeneratorService::class)
            ->forSoloMode($this->mainMode, (string) $this->subMode, $this->contentLang);
    }

    public function saveResult(
        $durationMs,
        $totalKeystrokes,
        $correctKeystrokes,
        $wpmHistory = [],
        $rawHistory = [],
        $missedChars = [],
        $drainEventCount = 0,
        $ghostWpm = null,
        $ghostLabel = null,
        $ghostCharsAtFinish = null,
        $errorEvents = []
    ) {
        // Gerbang mode: normalkan terhadap whitelist sebelum dipakai untuk score/mode_config,
        // supaya difficulty/sub-mode liar tak masuk DB & mencemari filter leaderboard.
        [$this->mainMode, $this->subMode] = $this->normalizeMode($this->mainMode, $this->subMode);

        // WPM/akurasi dari client TIDAK diterima — server selalu hitung ulang sendiri (anti-cheat).
        $totalKeystrokes = (int) $totalKeystrokes;
        $correctKeystrokes = (int) $correctKeystrokes;
        $incorrectKeystrokes = max(0, $totalKeystrokes - $correctKeystrokes);

        // Survival: metrik leaderboard = duration_seconds, bukan score. Kolom score jadi
        // stat sampingan (karakter benar selama bertahan); mode lain tak pakai kolom score.
        $score = $this->mainMode === 'survival' ? $correctKeystrokes : null;

        // Durasi dari client dalam milidetik; simpan dalam detik (boleh pecahan) agar WPM
        // server == WPM yang dilihat user saat mengetik.
        $duration = max(0.0, (float) $durationMs / 1000);

        // Server hitung ulang WPM/akurasi dari karakter & durasi (bukan percaya client);
        // sesi yang tak masuk akal ditolak, bukan disimpan.
        $antiCheat = app(AntiCheatService::class);

        $check = $antiCheat->check(
            $correctKeystrokes,
            $totalKeystrokes,
            $duration,
        );

        // Angka final selalu pakai hasil hitung ulang server (sumber kebenaran).
        $finalNetWpm = $check['net_wpm'];
        $finalRawWpm = $check['raw_wpm'];
        $finalAccuracy = $check['accuracy'];

        // Tolak hanya yang MEMANG layak ditolak (curang / sesi kosong / ngulur waktu di
        // survival). Dulu gerbangnya `! $check['valid']`, yang ikut membuang hasil
        // PENGETIK LAMBAT sungguhan -- throughput rendah itu lambat, bukan curang, dan
        // di time/words durasi tak bisa dipompa untuk keuntungan apa pun.
        if ($antiCheat->rejectsSoloResult($check['reasons'], $this->mainMode)) {
            session()->flash('result_rejected', __('typing.result_rejected'));

            // Full-load (lihat catatan di redirect result): hindari SPA-restore yang merusak.
            return $this->redirect(route('typing'));
        }

        $consistency = $this->computeConsistency($wpmHistory);

        $isPersonalBest = false;
        $previousBest = null;
        $levelData = null;
        $xpEarned = 0;
        $survivalPreviousBest = null;
        $isSurvivalPersonalBest = false;

        if (Auth::check()) {
            $user = Auth::user();

            // Ditangkap sebelum transaction menimpa highest_wpm. Survival dikecualikan dari rekor WPM.
            $previousBest = (float) $user->highest_wpm;
            $isPersonalBest = $this->mainMode !== 'survival' && $finalNetWpm > $previousBest;

            if ($this->mainMode === 'survival') {
                $survivalPreviousBest = TypingResult::where('user_id', $user->id)
                    ->where('mode', 'survival')
                    ->where('mode_config', (string) $this->subMode)
                    ->max('duration_seconds');

                $isSurvivalPersonalBest = $survivalPreviousBest === null
                    || $duration > (float) $survivalPreviousBest;
            }

            DB::transaction(function () use (
                &$xpEarned, $user, $duration, $finalNetWpm, $finalRawWpm, $finalAccuracy,
                $correctKeystrokes, $incorrectKeystrokes, $score

            ) {
                // EXP berbasis volume + bonus akurasi. Rumus dipusatkan di User::addExp()
                // -> satu sumber kebenaran dengan mode multiplayer; juga akumulasi total_xp & save.
                $xpEarned = $user->addExp($correctKeystrokes, $finalAccuracy);

                $typingResult = TypingResult::create([
                    'user_id' => $user->id,
                    'mode' => $this->mainMode, // 'time' | 'words' | 'survival'
                    // survival: difficulty ('easy'|'medium'|'hard', kunci filter leaderboard).
                    'mode_config' => (string) $this->subMode,
                    // Bahasa teks yang diketik (en|id) -- sudah dinormalisasi via TypingLanguage::resolve().
                    'language' => $this->contentLang,
                    'net_wpm' => $finalNetWpm,
                    'raw_wpm' => $finalRawWpm,
                    'accuracy' => $finalAccuracy,
                    'correct_chars' => $correctKeystrokes,
                    'incorrect_chars' => $incorrectKeystrokes,
                    'duration_seconds' => $duration,
                    'score' => $score, // jumlah kata bersih (survival), null untuk mode lain
                    'xp_earned' => $xpEarned,
                    'ghost_data' => null, // diisi selektif oleh ghost mode nanti
                ]);

                // Tautkan ke klaim war jika sesi ini mengerjakannya (fail-safe: attempt solo
                // tetap tersimpan normal apa pun hasilnya).
                $this->attachToWarClaim($typingResult);

                // Rekor WPM hanya dari mode terukur time/words; survival dikecualikan
                // (dicapai di bawah tekanan stamina, bukan apple-to-apple, cuma stat sampingan).
                if ($this->mainMode !== 'survival' && $finalNetWpm > (float) $user->highest_wpm) {
                    $user->highest_wpm = $finalNetWpm;
                    $user->save();
                }
            });

            // Snapshot setelah XP masuk: level & progres untuk ditampilkan di halaman result.
            $user = $user->fresh();
            $levelData = $user->levelData();

            // Catat achievement DI SINI -- di titik prestasinya benar-benar terjadi.
            // Dulu pencatatan menumpang render halaman Stats/Achievements, jadi pemain
            // yang tak pernah membukanya tak pernah tercatat, dan halaman GET jadi
            // punya efek samping tulis.
            app(AchievementService::class)->syncUnlocks($user);
        }

        // Ghost Mode: perbandingan ghost-vs-player efemeral (session-only), tidak ditulis
        // ke DB — attempt yang mendasari tetap tersimpan normal seperti mode biasa.
        // Gerbang server-side: ghost HANYA sah untuk time/words, apa pun yang dikirim klien.
        $ghostResult = null;
        if ($this->isGhostEligibleMode() && $ghostWpm !== null && (float) $ghostWpm > 0) {
            $ghostCharsAtFinish = (int) $ghostCharsAtFinish;
            $ghostResult = [
                'label' => (string) $ghostLabel,
                'wpm' => round((float) $ghostWpm, 2),
                'playerWon' => $correctKeystrokes > $ghostCharsAtFinish,
                'charDelta' => $correctKeystrokes - $ghostCharsAtFinish,
            ];
        }

        // Stream error per-karakter: tier PRESENTASI (session-only, tak pernah menyentuh
        // skor/XP/PB/leaderboard — jadi tak ada urusan dengan AntiCheatService). Tetap
        // disanitasi seperti ghost: bentuk divalidasi, nilai di-cast, panjang di-cap.
        // Beda dari missedChars yang mentah tapi aman karena cuma dibaca lewat lookup
        // kunci yang sudah diketahui — di sini `actual` benar-benar DIRENDER.
        $errorEvents = TypingErrorInspector::sanitize($errorEvents);

        session()->put('typing_result', [
            'wpm' => $finalNetWpm,
            'rawWpm' => $finalRawWpm,
            'accuracy' => $finalAccuracy,
            'time' => $duration,
            'mode' => $this->mainMode,
            'subMode' => $this->subMode,
            // Teks sesi ini disimpan agar result page bisa menawarkan "Retry" -- mengulang
            // rangkaian kata yang sama persis (hanya bermakna untuk mode words).
            'textToType' => $this->textToType,
            'score' => $score, // survival: karakter benar (stat sampingan); null untuk mode lain
            'totalKeystrokes' => $totalKeystrokes,
            'correctKeystrokes' => $correctKeystrokes,
            'incorrectKeystrokes' => $incorrectKeystrokes,
            'wpmHistory' => $wpmHistory,
            'rawHistory' => $rawHistory,
            'missedChars' => $missedChars,
            'xpEarned' => $xpEarned,
            'isPersonalBest' => $isPersonalBest,
            'previousBest' => $previousBest,
            'consistency' => $consistency,
            'levelData' => $levelData,
            'drainEventCount' => (int) $drainEventCount,
            'survivalPreviousBest' => $survivalPreviousBest !== null ? (float) $survivalPreviousBest : null,
            'isSurvivalPersonalBest' => $isSurvivalPersonalBest,
            'ghostResult' => $ghostResult,
            // Ringkas by design: cuma indeks. Halaman hasil merekonstruksi kata dari
            // textToType (sudah ada di atas) ketimbang kita menyimpan string dua kali.
            'errorEvents' => $errorEvents,
        ]);
        session()->save();

        // Muat-halaman-penuh (TANPA navigate:true): keluar /typing via SPA membuat tombol
        // Back me-restore snapshot mesin ketik -> @entangle undefined & $wire basi (tak bisa
        // ketik / stats kosong / finish menggantung). Full-load bikin Back memuat ulang bersih.
        $this->redirect(route('typing.result'));
    }

    // Consistency: seberapa stabil WPM sepanjang sesi (dari wpmHistory per-detik). 100% =
    // kecepatan rata sempurna. Presentasi saja, bukan anti-cheat. Butuh min 2 sampel & mean > 0.
    private function computeConsistency(array $history): ?int
    {
        $values = array_values(array_filter($history, fn ($v) => is_numeric($v)));
        $n = count($values);
        if ($n < 2) {
            return null;
        }

        $mean = array_sum($values) / $n;
        if ($mean <= 0) {
            return null;
        }

        $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / $n;
        $sd = sqrt($variance);

        return (int) round(max(0, 1 - $sd / $mean) * 100);
    }

    public function render()
    {
        return view('livewire.typing-engine');
    }
}
