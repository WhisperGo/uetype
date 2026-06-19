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
            $table->foreignId('text_id')->nullable()->constrained('texts')->onDelete('cascade');
            $table->enum('match_type', ['solo', '1v1', 'group']);
            $table->boolean('is_ranked')->default(false);
            $table->enum('mode_played', ['time', 'words', 'quote']);
            $table->integer('mode_config')->nullable();
            $table->enum('status', ['waiting', 'ongoing', 'completed', 'abandoned'])->default('waiting');
            $table->text('generated_text')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
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
            $table->enum('connection_status', ['connected', 'disconnected', 'abandoned'])->default('disconnected');
            $table->json('wpm_samples')->nullable();
            $table->json('heatmap_data')->nullable();
            $table->boolean('is_suspicious')->default(false);
            $table->json('cheat_summary')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('wpm');
            $table->index('created_at');
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
