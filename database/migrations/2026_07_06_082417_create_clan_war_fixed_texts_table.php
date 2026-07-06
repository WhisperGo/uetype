<?php

use App\Services\ClanWarModeCatalog;
use App\Support\TypingLanguage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Teks tetap untuk mode Words di Clan War. Digenerate SEKALI di sini lalu
     * beku selamanya, sehingga setiap pemain yang mengerjakan "Words {N}" di
     * war/clan mana pun mendapat daftar kata yang IDENTIK -- menutup celah
     * refresh (reroll kata sampai dapat yang pendek) sekaligus membuat
     * perbandingan antar-clan benar-benar apple-to-apple.
     */
    public function up(): void
    {
        Schema::create('clan_war_fixed_texts', function (Blueprint $table) {
            $table->id();
            $table->string('mode', 20);
            $table->string('mode_config', 10);
            $table->text('content');
            $table->timestamps();

            $table->unique(['mode', 'mode_config']);
        });

        $this->seedWordsTexts();
    }

    public function down(): void
    {
        Schema::dropIfExists('clan_war_fixed_texts');
    }

    /**
     * Isi satu baris per config Words (10/25/50/100) memakai teknik shuffle
     * wordlist yang SAMA dengan TypingEngine::generateText() (bahasa default),
     * supaya konten sejenis dengan sesi solo -- hanya saja dibekukan.
     */
    private function seedWordsTexts(): void
    {
        $path = TypingLanguage::wordlistPath(TypingLanguage::DEFAULT);

        if (! File::exists($path)) {
            return; // Tanpa wordlist, lewati; fallback ditangani di sisi baca.
        }

        $data = json_decode(File::get($path), true);

        if (! is_array($data) || ! isset($data['words']) || ! is_array($data['words'])) {
            return;
        }

        $words = $data['words'];
        $now = now();
        $rows = [];

        foreach (ClanWarModeCatalog::MODES as $mode) {
            if ($mode['mode'] !== 'words') {
                continue;
            }

            $limit = (int) $mode['config'];
            $selected = [];

            while (count($selected) < $limit) {
                shuffle($words);
                $needed = $limit - count($selected);
                $selected = array_merge($selected, array_slice($words, 0, $needed));
            }

            $rows[] = [
                'mode' => 'words',
                'mode_config' => $mode['config'],
                'content' => implode(' ', $selected),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows !== []) {
            DB::table('clan_war_fixed_texts')->insert($rows);
        }
    }
};
