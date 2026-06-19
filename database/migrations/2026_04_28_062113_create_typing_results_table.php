<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('typing_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('text_id')->nullable()->constrained('texts')->nullOnDelete();
            $table->string('mode', 20);
            $table->string('mode_config', 50)->nullable();
            $table->decimal('net_wpm', 6, 2);
            $table->decimal('raw_wpm', 6, 2);
            $table->decimal('accuracy', 5, 2);
            $table->integer('correct_chars');
            $table->integer('incorrect_chars');
            $table->float('duration_seconds');
            $table->integer('score')->nullable();
            $table->integer('xp_earned')->default(0);
            $table->json('ghost_data')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('typing_results');
    }
};
