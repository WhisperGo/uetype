<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A per-user "clear chat" marker, not a message deletion. Messages with
     * created_at <= cleared_before are hidden from this user only; other participants still
     * see the full history. "Clear all" = cleared_before now().
     */
    public function up(): void
    {
        Schema::create('message_clears', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            // Same target as messages: DM (other_user_id) OR clan_id.
            $table->foreignId('other_user_id')->nullable()->constrained('users')->onDelete('cascade');
            $table->foreignId('clan_id')->nullable()->constrained('clans')->onDelete('cascade');
            $table->timestamp('cleared_before');
            $table->timestamps();

            // One "clear" row per conversation partner/clan: re-clearing updates, doesn't stack.
            $table->unique(['user_id', 'other_user_id'], 'message_clears_dm_unique');
            $table->unique(['user_id', 'clan_id'], 'message_clears_clan_unique');
        });

        // The "exactly one target" guard is installed in the migration
        // 2026_07_20_100000_enforce_message_target_invariants, alongside the one for
        // `messages` -- one place that owns both invariants.
    }

    public function down(): void
    {
        Schema::dropIfExists('message_clears');
    }
};
