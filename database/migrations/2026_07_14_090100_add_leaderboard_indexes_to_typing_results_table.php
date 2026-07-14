<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Index untuk query TERPANAS di aplikasi: "rekor terbaik per user di satu
     * mode+config". Bentuknya selalu
     *
     *     WHERE mode = ? AND mode_config = ?  ... MAX(net_wpm) ... GROUP BY user_id
     *
     * dan dipakai leaderboard (tiap ganti tab/config), GhostPicker, personal best
     * di TypingEngine, Stats, sampai daftar ghost teman.
     *
     * Sebelum ini satu-satunya index adalah (user_id, created_at) -- tak satu pun
     * query di atas bisa memakainya, jadi semuanya full table scan. typing_results
     * adalah tabel yang paling cepat tumbuh (satu baris tiap kali siapa pun
     * mengetik), jadi biayanya naik terus seiring pemakaian.
     *
     * Kolom metrik ikut dimasukkan ke ujung index supaya MAX() bisa dilayani
     * langsung dari index (covering), tanpa menyentuh baris tabelnya.
     */
    public function up(): void
    {
        Schema::table('typing_results', function (Blueprint $table) {
            // Papan time & words (metrik: net_wpm).
            $table->index(['mode', 'mode_config', 'net_wpm'], 'typing_results_leaderboard_wpm_index');

            // Papan survival (metrik: duration_seconds -- lama bertahan, bukan wpm).
            $table->index(['mode', 'mode_config', 'duration_seconds'], 'typing_results_leaderboard_duration_index');

            // Rekor pribadi & lawan ghost: satu user, satu mode+config.
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
