<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index for the HOTTEST query in the app: "each user's best record in one mode+config".
     * The shape is always
     *
     *     WHERE mode = ? AND mode_config = ?  ... MAX(net_wpm) ... GROUP BY user_id
     *
     * and it's used by the leaderboard (on every tab/config switch), GhostPicker, personal
     * bests in TypingEngine, Stats, and the friends' ghost list.
     *
     * Before this the only index was (user_id, created_at) -- none of the queries above
     * could use it, so they were all full table scans. typing_results is the fastest-growing
     * table (one row every time anyone types), so the cost keeps rising with use.
     *
     * The metric column is appended to the index so MAX() can be served straight from the
     * index (covering), without touching the table rows.
     */
    public function up(): void
    {
        Schema::table('typing_results', function (Blueprint $table) {
            // Time & words boards (metric: net_wpm).
            $table->index(['mode', 'mode_config', 'net_wpm'], 'typing_results_leaderboard_wpm_index');

            // Survival board (metric: duration_seconds -- how long you survived, not wpm).
            $table->index(['mode', 'mode_config', 'duration_seconds'], 'typing_results_leaderboard_duration_index');

            // Personal records & ghost opponents: one user, one mode+config.
            $table->index(['user_id', 'mode', 'mode_config'], 'typing_results_user_mode_index');
        });
    }

    public function down(): void
    {
        Schema::table('typing_results', function (Blueprint $table) {
            $table->dropIndex('typing_results_leaderboard_wpm_index');
            $table->dropIndex('typing_results_leaderboard_duration_index');
            $table->dropIndex('typing_results_user_mode_index');
        });
    }
};
