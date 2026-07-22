<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The typed text's language (en|id) as a leaderboard dimension, alongside
     * mode + mode_config. Previously `contentLang` existed in TypingEngine but was dropped
     * on save, so the board couldn't separate English vs. Indonesian records.
     *
     * default('en') makes this migration non-breaking: old rows (seeder dummy data) and
     * every TypingResult::create([...]) in tests that doesn't mention language stay valid.
     *
     * The hottest leaderboard index has the shape
     *     WHERE mode = ? AND mode_config = ? AND language = ?  ... MAX(metric) ... GROUP BY user_id
     * so `language` is inserted BEFORE the metric column to keep the index covering.
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
