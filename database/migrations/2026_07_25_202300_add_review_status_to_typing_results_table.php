<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Review queue for suspicious solo results (anti-cheat report §7.5 / §7.6).
 *
 * A run that clears the hard gates but looks anomalous (a huge jump over the player's own
 * history, or a no-history debut far above a sane WPM) is not rejected -- honest players
 * DO improve. It is marked `pending` instead: still saved and visible on the player's own
 * profile, but held OUT of the public leaderboard until a human approves it. Everything else
 * defaults to `clear` and behaves exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('typing_results', function (Blueprint $table) {
            // clear | pending | approved | rejected
            $table->string('review_status', 12)->default('clear')->after('accuracy');
            // Why it was flagged (e.g. 'longitudinal_spike', 'no_history_high'); null when clear.
            $table->string('review_reason', 40)->nullable()->after('review_status');

            // Leaderboard queries filter on review_status, so index it alongside the metric.
            $table->index(['mode', 'mode_config', 'review_status', 'net_wpm'], 'typing_results_review_index');
        });
    }

    public function down(): void
    {
        Schema::table('typing_results', function (Blueprint $table) {
            $table->dropIndex('typing_results_review_index');
            $table->dropColumn(['review_status', 'review_reason']);
        });
    }
};
