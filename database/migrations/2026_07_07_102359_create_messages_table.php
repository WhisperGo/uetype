<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu tabel untuk dua jenis chat, target lewat kolom nullable:
     *   - DM: recipient_id terisi, clan_id null.
     *   - Clan chat: clan_id terisi, recipient_id null.
     *
     * Penjagaan DB-level-nya TIDAK dipasang di sini melainkan di migrasi
     * 2026_07_20_100000_enforce_message_target_invariants: tabel ini masih
     * diubah tiga migrasi berikutnya, dan di sqlite penambahan FOREIGN KEY
     * membangun ulang tabel sehingga trigger apa pun ikut terhapus.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('recipient_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('clan_id')->nullable()->constrained('clans')->onDelete('cascade');
            $table->text('body');
            $table->timestamp('read_at')->nullable(); // Hanya relevan utk DM, null utk clan chat.
            $table->timestamps();

            // Hindari full scan untuk riwayat DM & riwayat clan.
            $table->index(['sender_id', 'recipient_id']);
            $table->index(['recipient_id', 'read_at']);
            $table->index(['clan_id', 'created_at']);
        });

    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
