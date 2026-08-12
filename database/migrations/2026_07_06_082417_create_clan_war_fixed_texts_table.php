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
     * Fixed text for the Words mode in Clan War. Generated ONCE here then frozen forever,
     * so every player doing "Words {N}" in any war/clan gets an IDENTICAL word list --
     * closing the refresh exploit (rerolling words until you get short ones) and making
     * cross-clan comparison truly apples-to-apples.
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
     * Fill one row per Words config (10/25/50/100) using the SAME wordlist-shuffle
     * technique as TypingEngine::generateText() (default language), so the content is like
     * a solo session -- only frozen.
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
