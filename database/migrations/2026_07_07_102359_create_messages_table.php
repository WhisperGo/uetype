<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Satu tabel untuk DUA jenis chat: DM teman & chat clan. Target pesan
     * polimorfik lewat DUA kolom nullable (bukan satu tabel per jenis chat)
     * supaya query "riwayat pesan" bisa dipakai ulang oleh Message::between()
     * (DM) maupun Message::inClan() (clan) tanpa duplikasi skema:
     *   - DM: recipient_id terisi, clan_id null.
     *   - Clan chat: clan_id terisi, recipient_id null.
     * Constraint DB (bukan cuma validasi aplikasi) memastikan tepat SATU
     * target terisi -- lihat CHECK di bawah.
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

            // Mempercepat query "semua pesan antara user A & B" (DM) dan
            // "semua pesan clan X" tanpa full scan.
            $table->index(['sender_id', 'recipient_id']);
            $table->index(['recipient_id', 'read_at']);
            $table->index(['clan_id', 'created_at']);
        });

        // Tepat satu dari recipient_id/clan_id terisi -- gerbang DB-level,
        // bukan cuma disiplin aplikasi (mencegah baris rusak/ambigu).
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
