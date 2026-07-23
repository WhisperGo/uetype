<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One table for two kinds of chat, targeted via nullable columns:
     *   - DM: recipient_id set, clan_id null.
     *   - Clan chat: clan_id set, recipient_id null.
     *
     * The DB-level guard is NOT installed here but in the migration
     * 2026_07_20_100000_enforce_message_target_invariants, together with the matching
     * guard for `message_clears` -- same invariant, one place.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sender_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('recipient_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('clan_id')->nullable()->constrained('clans')->onDelete('cascade');
            $table->text('body');
            $table->timestamp('read_at')->nullable(); // Only relevant for DMs, null for clan chat.
            $table->timestamps();

            // Avoid a full scan for DM history & clan history.
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
