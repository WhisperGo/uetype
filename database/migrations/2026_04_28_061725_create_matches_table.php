<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('text_id')->constrained('texts')->onDelete('cascade')->nullable();
            $table->enum('match_type', ['solo_practice', 'ranked_duel', 'ranked_multiplayer']);
            $table->enum('status', ['waiting', 'ongoing', 'completed', 'cancelled'])->default('waiting');
            $table->enum('mode_played', ['wordlist', 'quote', 'code']);
            $table->text('generated_text')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('ended_at');
            $table->timestamps();
        });

        Schema::create('match_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->float('wpm', 10, 2)->default(0);
            $table->float('accuracy', 10, 2)->default(0);
            $table->integer('placement')->default(0);
            $table->integer('elo_change')->default(0);
            $table->string('connection_status', 30)->default('disconnected');
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('matches');
        Schema::dropIfExists('match_participants');
    }
};
