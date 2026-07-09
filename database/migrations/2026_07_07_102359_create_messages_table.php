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
     * CHECK di bawah menjamin tepat satu target terisi di level DB.
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

        // Gerbang DB-level, bukan sekadar disiplin aplikasi.
        DB::statement('ALTER TABLE messages ADD CONSTRAINT messages_exactly_one_target CHECK (
            (recipient_id IS NOT NULL AND clan_id IS NULL) OR
            (recipient_id IS NULL AND clan_id IS NOT NULL)
        )');
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
