<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Delete for me" per-MESSAGE. One row = one user hiding one message ONLY from
     * themselves; the original message row stays intact in the DB for everyone else. Same
     * pattern as message_clears, but granular per-message (not per-conversation).
     */
    public function up(): void
    {
        Schema::create('message_deletes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('message_id')->constrained('messages')->onDelete('cascade');
            $table->timestamps();
            // One user can't "delete for me" the same message twice.
            $table->unique(['user_id', 'message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_deletes');
    }
};
