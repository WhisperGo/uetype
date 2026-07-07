<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Penanda "clear chat" per-user -- BUKAN penghapusan pesan sungguhan.
     * Satu baris = satu user meng-clear satu percakapan (DM dgn user lain,
     * ATAU chat clan) sampai batas waktu tertentu. Pesan dengan
     * created_at <= cleared_before disembunyikan HANYA dari user ini;
     * partisipan lain tetap melihat riwayat penuh mereka sendiri.
     *
     * "Clear semua" direpresentasikan sebagai cleared_before = now() (semua
     * pesan sejauh ini otomatis lebih lama dari now()) -- tak perlu kolom
     * boolean terpisah, satu mekanisme timestamp cukup utk kedua opsi
     * (clear semua vs clear sebelum N hari yang lalu).
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

            // Satu user cuma punya SATU baris "clear" aktif per lawan-bicara/clan
            // -- clear ulang meng-update baris ini (naikkan cleared_before),
            // bukan menumpuk baris baru.
            $table->unique(['user_id', 'other_user_id'], 'message_clears_dm_unique');
            $table->unique(['user_id', 'clan_id'], 'message_clears_clan_unique');
        });

        DB::statement('ALTER TABLE message_clears ADD CONSTRAINT message_clears_exactly_one_target CHECK (
            (other_user_id IS NOT NULL AND clan_id IS NULL) OR
            (other_user_id IS NULL AND clan_id IS NOT NULL)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('message_clears');
    }
};
