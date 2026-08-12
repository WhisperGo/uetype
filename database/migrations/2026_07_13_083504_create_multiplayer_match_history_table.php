<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Permanent, append-only log of finished multiplayer races. Unlike
     * rooms/room_members (deleted the moment everyone leaves), this table is never
     * cleared, so it can back multiplayer stats the same way typing_results backs
     * solo stats.
     */
    public function up(): void
    {
        Schema::create('multiplayer_match_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('room_code', 10); // Reference only, not a FK -- the room row is usually already gone.
            $table->unsignedTinyInteger('place');
            $table->unsignedTinyInteger('player_count');
            $table->unsignedInteger('wpm'); // Server-computed Net WPM (see MultiplayerLobby::updateRaceProgress).
            $table->decimal('accuracy', 5, 2);
            $table->unsignedInteger('finished_time_seconds')->nullable(); // Mirrors room_members; giveUp() uses the 999s sentinel, not null.
            $table->unsignedInteger('xp_earned')->default(0);
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('multiplayer_match_history');
    }
};
