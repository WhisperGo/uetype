<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Bahasa teks yang diketik (en|id) sebagai dimensi leaderboard, sejajar dengan
     * mode + mode_config. Sebelumnya `contentLang` ada di TypingEngine tapi dibuang
     * saat simpan, jadi papan tak bisa memisahkan rekor Inggris vs Indonesia.
     *
     * default('en') membuat migrasi non-breaking: baris lama (data dummy seeder) dan
     * setiap TypingResult::create([...]) di test yang tak menyebut language tetap sah.
     *
     * Index leaderforce terpanas berbentuk
     *     WHERE mode = ? AND mode_config = ? AND language = ?  ... MAX(metrik) ... GROUP BY user_id
     * jadi `language` disisipkan SEBELUM kolom metrik supaya index tetap covering.
     */
    public function up(): void
    {
        Schema::table('typing_results', function (Blueprint $table) {
            $table->string('language', 5)->default('en')->after('mode_config');
        });

        Schema::table('typing_results', function (Blueprint $table) {
            // Papan time & words (metrik: net_wpm) + bahasa.
            $table->dropIndex('typing_results_leaderboard_wpm_index');
            $table->index(['mode', 'mode_config', 'language', 'net_wpm'], 'typing_results_leaderboard_wpm_index');

            // Papan survival (metrik: duration_seconds) + bahasa.
            $table->dropIndex('typing_results_leaderboard_duration_index');
            $table->index(['mode', 'mode_config', 'language', 'duration_seconds'], 'typing_results_leaderboard_duration_index');
        });
    }

    public function down(): void
    {
        Schema::table('typing_results', function (Blueprint $table) {
            $table->dropIndex('typing_results_leaderboard_wpm_index');
            $table->index(['mode', 'mode_config', 'net_wpm'], 'typing_results_leaderboard_wpm_index');

            $table->dropIndex('typing_results_leaderboard_duration_index');
            $table->index(['mode', 'mode_config', 'duration_seconds'], 'typing_results_leaderboard_duration_index');

            $table->dropColumn('language');
        });
    }
};
