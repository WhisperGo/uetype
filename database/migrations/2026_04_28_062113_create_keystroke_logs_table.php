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
        Schema::create('keystroke_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_participant_id')->constrained('match_participants')->onDelete('cascade');
            $table->json('raw_keystrokes');
            $table->json('heatmap_data');
            $table->boolean('is_bot_flag')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('keystroke_logs');
    }
};
