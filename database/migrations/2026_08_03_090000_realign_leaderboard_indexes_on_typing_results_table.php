<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Realign the leaderboard indexes with the query the leaderboard actually runs.
 *
 * Three migrations each added a dimension to the SAME query without knowing about the others,
 * and the result was that no single index covered it any more:
 *
 *   2026_07_14  (mode, mode_config, net_wpm)                    <- the original "covering" intent
 *   2026_07_16  (mode, mode_config, language, net_wpm)          <- language inserted before the metric
 *   2026_07_25  (mode, mode_config, review_status, net_wpm)     <- a SECOND index, without language
 *
 * The query filters mode + mode_config + language + review_status and then takes MAX(metric)
 * grouped by user. Against the first index the prefix breaks at review_status; against the
 * second it breaks at language. MySQL could only ever use (mode, mode_config) from either, so
 * the documented covering-index intent had quietly stopped being true.
 *
 * One index per board, in filter order with the metric last, restores it. The review index is
 * dropped rather than kept: its every prefix is now a prefix of the wpm index, so it earned
 * nothing and cost a write on every insert.
 *
 * Also adds (user_id, duration_seconds) for the leaderboard ELIGIBILITY gate
 * (TypingResult::LEADERBOARD_MIN_TYPING_SECONDS), which aggregates SUM(duration_seconds) per
 * user across the whole table with no filter at all -- twice per render. Without an index it
 * is a full scan of the fastest-growing table in the app, on the page people click most, and
 * its cost cannot be reduced by anything the user chooses on screen.
 *
 * Verify with EXPLAIN against the real leaderboard query rather than by assumption; the shape
 * lives in resources/views/livewire/leaderboard.blade.php ($scoped + $bestPerUser).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('typing_results', function (Blueprint $table) {
            // Time & words boards (metric: net_wpm).
            $table->dropIndex('typing_results_leaderboard_wpm_index');
            $table->index(
                ['mode', 'mode_config', 'language', 'review_status', 'net_wpm'],
                'typing_results_leaderboard_wpm_index'
            );

            // Survival board (metric: duration_seconds -- how long you survived, not wpm).
            $table->dropIndex('typing_results_leaderboard_duration_index');
            $table->index(
                ['mode', 'mode_config', 'language', 'review_status', 'duration_seconds'],
                'typing_results_leaderboard_duration_index'
            );

            // Fully contained in the wpm index above.
            $table->dropIndex('typing_results_review_index');

            // Eligibility gate: SUM(duration_seconds) GROUP BY user_id.
            $table->index(['user_id', 'duration_seconds'], 'typing_results_eligibility_index');
        });
    }

    public function down(): void
    {
        Schema::table('typing_results', function (Blueprint $table) {
            $table->dropIndex('typing_results_eligibility_index');

            $table->index(['mode', 'mode_config', 'review_status', 'net_wpm'], 'typing_results_review_index');

            $table->dropIndex('typing_results_leaderboard_duration_index');
            $table->index(
                ['mode', 'mode_config', 'language', 'duration_seconds'],
                'typing_results_leaderboard_duration_index'
            );

            $table->dropIndex('typing_results_leaderboard_wpm_index');
            $table->index(
                ['mode', 'mode_config', 'language', 'net_wpm'],
                'typing_results_leaderboard_wpm_index'
            );
        });
    }
};
