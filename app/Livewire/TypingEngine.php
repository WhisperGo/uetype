<?php

namespace App\Livewire;

use App\Enums\ClanWarStatus;
use App\Models\ClanWarFixedText;
use App\Models\ClanWarModeClaim;
use App\Models\Text;
use App\Models\TypingResult;
use App\Models\User;
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

    // Whitelist sub-mode sah per mode utama; gerbang server-side karena mainMode/subMode
    // dikendalikan client dan mode_config masuk kunci filter leaderboard. 'quote' tanpa sub-mode.
    private const ALLOWED_SUBMODES = [
        'time' => ['15', '30', '60', '120'],
        'words' => ['10', '25', '50', '100'],
        'quote' => [],
        'survival' => ['easy', 'medium', 'hard'],
    ];

    public $textToType;

    public string $contentLang = TypingLanguage::DEFAULT;

    public int $typingSessionKey = 0;

    // ID baris `texts` yang diketik: terisi untuk quote (dari DB), null untuk time/words
    // (dirakit acak dari wordlist JSON).
    public $textId = null;

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
            if (session()->has('typing_preferences')) {
                $prefs = session('typing_preferences');
                $this->mainMode = $prefs['mode'] ?? 'time';
                $this->subMode = $prefs['subMode'] ?? '30';
            }
        }

        // Bahasa konten dipulihkan terpisah: war hanya mengunci mode/config, bukan bahasa.
        if (session()->has('typing_preferences')) {
            $this->contentLang = TypingLanguage::resolve(session('typing_preferences')['contentLang'] ?? null);
        }

        // Ghost deep-link diproses setelah war-lock supaya war tetap menang; hanya
        // berlaku untuk sesi solo & mode time/words.
        if (! $this->warLock) {
            $this->resolveGhostDeepLink();
        }

        $this->generateText();
    }

    /**
     * Pasang lawan ghost dari ?ghost=&mode=&config= (dari baris leaderboard). WPM
     * selalu diturunkan ulang dari DB, sama pola dengan GhostPicker::selectOpponent
     * ('leaderboard'). Hanya time/words; param liar/tanpa rekor diabaikan (fail-safe).
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

        $label = User::find($this->ghostUserId)?->username ?? 'Leaderboard';

        $this->dispatch('ghost-selected', type: 'leaderboard', wpm: (float) $best, label: $label);

        $this->clearGhostDeepLinkParams();
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

        $allowed = self::ALLOWED_SUBMODES[$main];

        if ($main === 'quote') {
            return ['quote', null];
        }

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

        $this->dispatch(
            'mode-changed',
            text: $this->textToType,
            main: $this->mainMode,
            sub: $this->subMode
        );
    }

    // Ganti bahasa konten yang diketik, terpisah dari bahasa UI. Boleh kapan saja,
    // termasuk saat war-lock (tak mengubah mode/skor).
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

        // Clan War mode Words pakai teks TETAP (identik untuk semua pemain di config yang
        // sama) demi keadilan, bukan dirakit acak. Time/Survival war cukup restart-nya
        // yang diblokir.
        if ($this->warClaimId !== null && $this->resolveWarClaim()) {
            if ($this->mainMode === 'words') {
                $fixed = ClanWarFixedText::forWords($this->subMode);

                // Fallback ke generate biasa kalau baris tetap tak ada (layar tak pernah kosong).
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

            // Fallback: kalau belum ada kutipan untuk bahasa terpilih, ambil kutipan apa pun.
            $text ??= Text::where('mode', 'quote')->inRandomOrder()->first();

            $this->textToType = $text ? $text->content : 'Kutipan belum tersedia di database.';
            $this->textId = $text?->id;
        } else {
            // Time/words/survival: teks dirakit acak dari wordlist JSON sesuai bahasa konten.
            $this->textId = null;

            $path = TypingLanguage::wordlistPath($this->contentLang);

            if (File::exists($path)) {
                $jsonString = File::get($path);
                $data = json_decode($jsonString, true);

                if (is_array($data) && isset($data['words']) && is_array($data['words'])) {
                    $wordsArray = $data['words'];
                    shuffle($wordsArray);

                    // words = jumlah yang dipilih; survival butuh stok panjang (tanpa batas
                    // waktu/kata); time cukup 350 kata.
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
        $check = app(AntiCheatService::class)->check(
            $correctKeystrokes,
            $totalKeystrokes,
            $duration,
        );

        // Angka final selalu pakai hasil hitung ulang server (sumber kebenaran).
        $finalNetWpm = $check['net_wpm'];
        $finalRawWpm = $check['raw_wpm'];
        $finalAccuracy = $check['accuracy'];

        // Sesi tidak valid: tolak, jangan simpan/beri EXP/naikkan rekor.
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
                    'text_id' => $this->textId, // terisi untuk quote, null untuk time/words
                    'mode' => $this->mainMode, // 'time' | 'words' | 'quote' | 'survival'
                    // survival: difficulty ('easy'|'medium'|'hard', kunci filter leaderboard); quote: null.
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

                // Tautkan ke klaim war jika sesi ini mengerjakannya (fail-safe: attempt solo
                // tetap tersimpan normal apa pun hasilnya).
                $this->attachToWarClaim($typingResult);

                // Rekor WPM hanya dari mode terukur time/words/quote; survival dikecualikan
                // (dicapai di bawah tekanan stamina, bukan apple-to-apple, cuma stat sampingan).
                if ($this->mainMode !== 'survival' && $finalNetWpm > (float) $user->highest_wpm) {
                    $user->highest_wpm = $finalNetWpm;
                    $user->save();
                }
            });

            // Snapshot setelah XP masuk: level & progres untuk ditampilkan di halaman result.
            $levelData = $user->fresh()->levelData();
        }

        // Ghost Mode: perbandingan ghost-vs-player efemeral (session-only), tidak ditulis
        // ke DB — attempt yang mendasari tetap tersimpan normal seperti mode biasa.
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
