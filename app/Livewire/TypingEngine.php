<?php

namespace App\Livewire;

use App\Enums\ClanWarStatus;
use App\Models\ClanWarFixedText;
use App\Models\ClanWarModeClaim;
use App\Models\Text;
use App\Models\TypingResult;
use App\Services\AntiCheatService;
use App\Services\ClanWarScorer;
use App\Support\TypingLanguage;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Livewire\Attributes\Url;
use Livewire\Component;

class TypingEngine extends Component
{
    // Mode Utama: 'time', 'words', 'quote'
    public $mainMode = 'time';

    // Sub Mode (Pilihan angka/panjang)
    public $subMode = '30';

    // Whitelist sub-mode yang sah PER mode utama. Dipakai sebagai gerbang server-side:
    // mainMode & subMode adalah properti Livewire publik (dikendalikan client), jadi
    // request yang dipalsukan bisa mengirim mode/difficulty sembarang. Karena mode_config
    // (difficulty survival) jadi kunci filter leaderboard, nilai liar HARUS ditolak —
    // sejalan dengan prinsip "jangan percaya angka client untuk hal yang masuk leaderboard".
    // 'quote' tidak punya sub-mode (mode_config = null).
    private const ALLOWED_SUBMODES = [
        'time' => ['15', '30', '60', '120'],
        'words' => ['10', '25', '50', '100'],
        'quote' => [],
        'survival' => ['easy', 'medium', 'hard'],
    ];

    public $textToType;

    public string $contentLang = TypingLanguage::DEFAULT;

    public int $typingSessionKey = 0;

    // ID baris `texts` yang sedang diketik. Terisi untuk mode quote (teks dari DB),
    // null untuk time/words (teks dirakit acak dari wordlist JSON, bukan dari satu baris texts).
    public $textId = null;

    // Clan War: id ClanWarModeClaim yang sedang dikerjakan (dari query string
    // ?war_claim=). Kalau terisi & valid, mode dikunci ke mode/config klaim itu
    // dan hasil ketik otomatis jadi war attempt. Null = sesi solo biasa.
    #[Url(as: 'war_claim')]
    public ?int $warClaimId = null;

    // Detail mode klaim war (mode+config) supaya view bisa tampilkan banner.
    // Null kalau tidak sedang war-lock.
    public ?array $warLock = null;

    // Ghost deep-link dari leaderboard: ?ghost=<user_id>&mode=<time|words>&config=<sub>.
    // Kalau terisi & valid, lawan ghost dipasang otomatis saat load dengan WPM
    // yang DITURUNKAN ULANG dari DB (bukan dari client) — sejalan dgn GhostPicker.
    #[Url(as: 'ghost')]
    public ?int $ghostUserId = null;

    #[Url(as: 'mode')]
    public ?string $ghostMode = null;

    #[Url(as: 'config')]
    public ?string $ghostConfig = null;

    public function mount()
    {
        // Cek war-lock DULU: kalau ?war_claim= valid, mode dipaksa sesuai klaim
        // dan preferensi session diabaikan. Kalau tak valid, warClaimId di-reset
        // ke null dan halaman berperilaku sebagai sesi solo biasa (fail-safe).
        $claim = $this->resolveWarClaim();

        if ($claim) {
            [$this->mainMode, $this->subMode] = $this->normalizeMode($claim->mode, $claim->mode_config);
            $this->warLock = ['mode' => $this->mainMode, 'config' => $this->subMode];
        } else {
            $this->warClaimId = null;

            // Pulihkan preferensi sebelumnya jika ada (hanya untuk sesi solo biasa).
            if (session()->has('typing_preferences')) {
                $prefs = session('typing_preferences');
                $this->mainMode = $prefs['mode'] ?? 'time';
                $this->subMode = $prefs['subMode'] ?? '30';
            }
        }

        // Bahasa konten yang diketik dipulihkan terpisah dari war-lock: war hanya
        // mengunci mode/config, bukan bahasa kata/kutipan yang diketik.
        if (session()->has('typing_preferences')) {
            $this->contentLang = TypingLanguage::resolve(session('typing_preferences')['contentLang'] ?? null);
        }

        // Ghost deep-link (dari leaderboard). Diproses SETELAH war-lock supaya war
        // tetap menang; hanya berlaku untuk sesi solo biasa & mode time/words.
        if (! $this->warLock) {
            $this->resolveGhostDeepLink();
        }

        $this->generateText();
    }

    /**
     * Pasang lawan ghost dari query string ?ghost=&mode=&config= (dibuka dari
     * baris leaderboard). Mode diselaraskan lewat normalizeMode; WPM lawan
     * SELALU diturunkan ulang dari DB di sini (bukan dari client), pola sama
     * dengan GhostPicker::selectOpponent('leaderboard'). Ghost hanya berlaku
     * untuk time/words. Param liar / user tanpa rekor di mode itu diabaikan
     * (halaman jadi sesi solo biasa, fail-safe seperti war_claim).
     */
    private function resolveGhostDeepLink(): void
    {
        if (! $this->ghostUserId || ! Auth::check()) {
            $this->clearGhostDeepLinkParams();

            return;
        }

        if (! in_array($this->ghostMode, ['time', 'words'], true)) {
            $this->clearGhostDeepLinkParams();

            return;
        }

        [$main, $sub] = $this->normalizeMode($this->ghostMode, $this->ghostConfig);

        $best = TypingResult::where('user_id', $this->ghostUserId)
            ->where('mode', $main)
            ->where('mode_config', $sub)
            ->max('net_wpm');

        if ($best === null || (float) $best <= 0) {
            $this->clearGhostDeepLinkParams();

            return;
        }

        $this->mainMode = $main;
        $this->subMode = $sub;

        $label = \App\Models\User::find($this->ghostUserId)?->username ?? 'Leaderboard';

        $this->dispatch('ghost-selected', type: 'leaderboard', wpm: (float) $best, label: $label);

        $this->clearGhostDeepLinkParams();
    }

    /**
     * Kosongkan param ghost supaya tak nyangkut di URL/state Livewire setelah
     * diproses (mencegah refresh/navigate memasang ulang atau URL jadi kotor).
     */
    private function clearGhostDeepLinkParams(): void
    {
        $this->ghostUserId = null;
        $this->ghostMode = null;
        $this->ghostConfig = null;
    }

    /**
     * Ambil & validasi baris ClanWarModeClaim dari $warClaimId: harus milik
     * clan AKTIF user saat ini, war-nya masih Ongoing, dan belum disubmit.
     * Null kalau tak ada/tak valid -- sengaja tidak melempar error supaya
     * query string yang usil hanya di-abaikan, bukan merusak halaman.
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

        // War harus masih berjalan.
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
     * Tautkan hasil ketik ke klaim war (kalau sesi ini war-lock & masih
     * valid). Re-validasi ulang di sini (bukan cuma percaya $warClaimId dari
     * client) & pakai update BERSYARAT `whereNull('typing_result_id')` supaya
     * dua submit paralel tak bisa dua-duanya mengisi klaim yang sama
     * (yang kedua meng-update 0 baris & tak berpengaruh). Dipanggil di dalam
     * DB::transaction saveResult() memakai $typingResult yang baru dibuat.
     */
    private function attachToWarClaim(TypingResult $typingResult): void
    {
        $claim = $this->resolveWarClaim();

        if (! $claim) {
            return;
        }

        $points = ClanWarScorer::score($claim->mode, $claim->mode_config, $typingResult);

        // Update bersyarat: hanya isi kalau masih belum tersubmit (idempoten,
        // race-safe terhadap submit dobel dari tab lain).
        ClanWarModeClaim::where('id', $claim->id)
            ->whereNull('typing_result_id')
            ->update([
                'typing_result_id' => $typingResult->id,
                'user_id' => Auth::id(),
                'points' => $points,
            ]);
    }

    // Validasi mode utama + sub-mode terhadap whitelist. Mengembalikan pasangan
    // [mainMode, subMode] yang sudah dinormalkan (fallback ke default aman jika liar).
    // Satu sumber kebenaran dipakai oleh setMode (saat ganti mode) DAN saveResult
    // (gerbang sebelum persist) supaya nilai client tak pernah lolos mentah ke DB.
    private function normalizeMode($main, $sub): array
    {
        if (! array_key_exists($main, self::ALLOWED_SUBMODES)) {
            return ['time', '30'];
        }

        $allowed = self::ALLOWED_SUBMODES[$main];

        // Quote tak punya sub-mode; mode lain harus cocok dengan whitelist-nya.
        if ($main === 'quote') {
            return ['quote', null];
        }

        $sub = (string) $sub;
        if (! in_array($sub, $allowed, true)) {
            $sub = $allowed[0]; // default aman pertama (mis. time→'15', survival→'easy')
        }

        return [$main, $sub];
    }

    // Fungsi untuk mengganti mode dan ambil teks baru
    public function setMode($main, $sub)
    {
        // War-lock: saat mengerjakan war attempt, mode TAK BOLEH diganti
        // (tombol juga di-disable di view, ini gerbang server-side-nya).
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

        // Kirim event dengan detail teks baru, mode, dan sub-mode
        $this->dispatch(
            'mode-changed',
            text: $this->textToType,
            main: $this->mainMode,
            sub: $this->subMode
        );
    }

    // Ganti bahasa KONTEN yang diketik (kata/kutipan), terpisah dari bahasa UI.
    // Boleh dilakukan kapan saja, termasuk saat war-lock (tak mengubah mode/skor).
    public function setContentLang($lang)
    {
        $this->contentLang = TypingLanguage::resolve($lang);

        session()->put('typing_preferences', [
            'mode' => $this->mainMode,
            'subMode' => $this->subMode,
            'contentLang' => $this->contentLang,
        ]);
        session()->save();

        $this->generateText();

        $this->dispatch(
            'mode-changed',
            text: $this->textToType,
            main: $this->mainMode,
            sub: $this->subMode
        );
    }

    public function restart()
    {
        // War-lock: saat mengerjakan war attempt, teks TAK BOLEH di-reroll.
        // Ini menutup celah "refresh sampai dapat kata pendek" untuk KETIGA
        // mode war (time/words/survival). Tombol juga di-disable di view; ini
        // gerbang server-side-nya (client tak dipercaya). Filosofi: sekali
        // klaim, satu kesempatan -- tak ada mengintip lalu mengulang.
        if ($this->warClaimId !== null && $this->resolveWarClaim()) {
            return;
        }

        $this->generateText(); // Ambil teks baru berdasarkan mainMode & subMode yang ada

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

        // Clan War, mode Words: pakai teks TETAP (identik untuk semua pemain di
        // config yang sama, di war/clan mana pun) alih-alih merakit acak. Ini
        // kunci keadilan -- tak ada yang bisa reroll dapat kata pendek, dan
        // clan A vs clan B benar-benar mengetik teks yang sama. Time & Survival
        // war tidak diberi teks tetap (teksnya cuma buffer yang dipotong durasi/
        // kematian), cukup restart-nya yang sudah diblokir.
        if ($this->warClaimId !== null && $this->resolveWarClaim()) {
            if ($this->mainMode === 'words') {
                $fixed = ClanWarFixedText::forWords($this->subMode);

                // Fallback ke generate biasa hanya kalau baris tetap tak ada
                // (mis. wordlist absen saat migrasi) -- layar tak pernah kosong.
                if ($fixed !== null) {
                    $this->textId = null;
                    $this->textToType = $fixed;

                    return;
                }
            }
        }

        if ($this->mainMode === 'quote') {
            $text = Text::where('mode', 'quote')
                ->whereHas('language', fn ($q) => $q->where('code', $this->contentLang))
                ->inRandomOrder()
                ->first();

            // Fallback: kalau belum ada kutipan untuk bahasa terpilih, ambil kutipan apa pun
            // supaya layar mengetik tidak pernah kosong.
            $text ??= Text::where('mode', 'quote')->inRandomOrder()->first();

            $this->textToType = $text ? $text->content : 'Kutipan belum tersedia di database.';
            // Simpan ID quote agar hasil sesi bisa mereferensikan teks yang diketik.
            $this->textId = $text?->id;
        } else {
            // Teks time/words dirakit acak dari JSON, tidak terikat ke satu baris texts.
            $this->textId = null;

            // Mode time/words/survival merakit teks dari wordlist JSON sesuai bahasa
            // konten yang dipilih user (terpisah dari bahasa UI).
            $path = TypingLanguage::wordlistPath($this->contentLang);

            if (File::exists($path)) {
                $jsonString = File::get($path);
                $data = json_decode($jsonString, true);

                // Pastikan data['words'] ada dan berupa array
                if (is_array($data) && isset($data['words']) && is_array($data['words'])) {
                    $wordsArray = $data['words'];
                    shuffle($wordsArray);

                    // Jika mode words, kita berikan sesuai jumlah yang dipilih.
                    // Survival tidak punya batas waktu/kata, jadi butuh stok kata panjang
                    // (kalau benar-benar habis, finish() tetap terpanggil saat teks selesai).
                    // Selain itu (time) cukup 350 kata.
                    $limit = match ($this->mainMode) {
                        'words' => (int) $this->subMode,
                        'survival' => 500,
                        default => 350,
                    };

                    $selectedWords = [];
                    while (count($selectedWords) < $limit) {
                        shuffle($wordsArray);
                        $needed = $limit - count($selectedWords);
                        $selectedWords = array_merge($selectedWords, array_slice($wordsArray, 0, $needed));
                    }

                    $this->textToType = mb_strtolower(implode(' ', $selectedWords));
                } else {
                    $this->textToType = 'error: struktur file json tidak valid';
                }
            } else {
                $this->textToType = 'error: file wordlist tidak ditemukan';
            }
        }
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
        $ghostCharsAtFinish = null
    ) {
        // Gerbang mode: mainMode/subMode adalah properti publik yang dikendalikan client.
        // Normalkan terhadap whitelist SEBELUM dipakai untuk menentukan score/mode_config,
        // supaya difficulty/sub-mode liar tak pernah masuk DB & mencemari filter leaderboard.
        [$this->mainMode, $this->subMode] = $this->normalizeMode($this->mainMode, $this->subMode);

        // Catatan: WPM/akurasi dari client TIDAK diterima sebagai parameter — server
        // selalu menghitung ulang sendiri dari jumlah karakter & durasi (anti-cheat).
        $totalKeystrokes = (int) $totalKeystrokes;
        $correctKeystrokes = (int) $correctKeystrokes;
        $incorrectKeystrokes = max(0, $totalKeystrokes - $correctKeystrokes);

        // Survival (model stamina): metrik leaderboard = durasi bertahan (duration_seconds),
        // BUKAN lagi kolom score. Kolom score dipakai sebagai stat sampingan: jumlah karakter
        // benar selama bertahan. Mode lain tidak memakai kolom score.
        $score = $this->mainMode === 'survival' ? $correctKeystrokes : null;

        // Durasi dikirim client dalam MILIDETIK (presisi penuh, sama dengan perhitungan live).
        // Simpan dalam detik (boleh pecahan) agar WPM server == WPM yang dilihat user saat mengetik.
        $duration = max(0.0, (float) $durationMs / 1000);

        // Validasi kewajaran server-side (requirement Scoring & Stats bag. 5):
        // server HITUNG ULANG WPM/akurasi dari karakter & durasi (bukan percaya angka client),
        // lalu menjadi gerbang — sesi yang tidak masuk akal DITOLAK, bukan disimpan.
        $check = app(AntiCheatService::class)->check(
            $correctKeystrokes,
            $totalKeystrokes,
            $duration,
        );

        // Angka final selalu pakai hasil hitung ulang server (sumber kebenaran).
        $finalNetWpm = $check['net_wpm'];
        $finalRawWpm = $check['raw_wpm'];
        $finalAccuracy = $check['accuracy'];

        // Sesi tidak valid: tolak. Jangan simpan, jangan beri EXP, jangan naikkan rekor.
        if (! $check['valid']) {
            session()->flash('result_rejected', __('typing.result_rejected'));

            return $this->redirect(route('typing'), navigate: true);
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

            // Ditangkap SEBELUM transaction menimpa highest_wpm. Survival dikecualikan
            // dari rekor WPM (konsisten dengan aturan di bawah).
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
                // EXP berbasis volume + bonus akurasi (requirement Level/EXP bag. 1).
                // Rumus dipusatkan di User::addExp() -> SATU sumber kebenaran dengan
                // mode multiplayer. addExp() sekaligus mengakumulasi ke total_xp & save.
                $xpEarned = $user->addExp($correctKeystrokes, $finalAccuracy);

                // Satu sesi solo valid = satu baris di typing_results (requirement Database).
                $typingResult = TypingResult::create([
                    'user_id' => $user->id,
                    'text_id' => $this->textId, // terisi untuk quote, null untuk time/words
                    'mode' => $this->mainMode, // 'time' | 'words' | 'quote' | 'survival'
                    // mode_config: detail sub-mode. time/words = angka, survival = difficulty
                    // ('easy'|'medium'|'hard' — kunci filter leaderboard per-difficulty), quote = null.
                    'mode_config' => $this->mainMode === 'quote' ? null : (string) $this->subMode,
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

                // Clan War: kalau sesi ini mengerjakan klaim war, hubungkan hasil ke
                // klaim & hitung poin. Attempt solo di atas TETAP tersimpan normal
                // apa pun hasilnya -- ini cuma menautkannya ke war (fail-safe: kalau
                // klaim ternyata tak valid lagi, hasil solo tetap ada, war tak terisi).
                $this->attachToWarClaim($typingResult);

                // Rekor WPM HANYA dari mode terukur-waktu/teks (time/words/quote). Survival
                // sengaja DIKECUALIKAN: WPM-nya dicapai di bawah tekanan stamina (bukan apple-to-
                // apple dengan run standar) dan di doc selalu berstatus "stat sampingan", bukan
                // metrik utama. Memasukkannya akan mencemari rekor profil pemain.
                if ($this->mainMode !== 'survival' && $finalNetWpm > (float) $user->highest_wpm) {
                    $user->highest_wpm = $finalNetWpm;
                    $user->save();
                }
            });

            // Snapshot setelah XP masuk: level & progres untuk ditampilkan di halaman result.
            $levelData = $user->fresh()->levelData();
        }

        // Ghost Mode: perbandingan ghost-vs-player EFEMERAL (session-only). TIDAK ditulis
        // ke kolom ghost_data / typing_results manapun — attempt yang mendasari tetap
        // tersimpan normal di atas (XP, highest_wpm, baris typing_results) persis seperti
        // mode biasa; ghost murni overlay visual, jadi hanya hasil bandingnya yang
        // "sekali pakai" untuk ditampilkan di halaman hasil.
        $ghostResult = null;
        if ($ghostWpm !== null && (float) $ghostWpm > 0) {
            $ghostCharsAtFinish = (int) $ghostCharsAtFinish;
            $ghostResult = [
                'label' => (string) $ghostLabel,
                'wpm' => round((float) $ghostWpm, 2),
                'playerWon' => $correctKeystrokes > $ghostCharsAtFinish,
                'charDelta' => $correctKeystrokes - $ghostCharsAtFinish,
            ];
        }

        session()->put('typing_result', [
            'wpm' => $finalNetWpm,
            'rawWpm' => $finalRawWpm,
            'accuracy' => $finalAccuracy,
            'time' => $duration,
            'mode' => $this->mainMode,
            'subMode' => $this->subMode,
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
        ]);
        session()->save();

        $this->redirect(route('typing.result'), navigate: true);
    }

    // Consistency: seberapa stabil WPM sepanjang sesi (turunan dari wpmHistory per-detik).
    // 100% = kecepatan rata sempurna; makin sering tersendat → makin rendah. Bukan metrik
    // anti-cheat, hanya presentasi. Butuh minimal 2 sampel & mean > 0, selain itu null.
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
