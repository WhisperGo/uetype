<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda "clear chat" per-user, bukan penghapusan pesan. Pesan dengan
     * created_at <= cleared_before disembunyikan hanya dari user ini; partisipan
     * lain tetap melihat riwayat penuh. "Clear semua" = cleared_before now().
     */
    public function up(): void
    {
        Schema::create('message_clears', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            // Target sama seperti messages: DM (other_user_id) ATAU clan_id.
            $table->foreignId('other_user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('clan_id')->nullable()->constrained('clans')->onDelete('cascade');
            $table->timestamp('cleared_before');
            $table->timestamps();

            // Satu baris "clear" per lawan-bicara/clan: clear ulang meng-update, bukan menumpuk.
            $table->unique(['user_id', 'other_user_id'], 'message_clears_dm_unique');
            $table->unique(['user_id', 'clan_id'], 'message_clears_clan_unique');
        });

        // Penjagaan "tepat satu target" dipasang di migrasi
        // 2026_07_20_100000_enforce_message_target_invariants, bersama milik
        // `messages` -- satu tempat yang memiliki kedua invarian ini.
    }

    public function down(): void
    {
        Schema::dropIfExists('message_clears');
    }
};
