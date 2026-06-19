<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->string('room_code', 10)->unique();
            $table->foreignId('host_user_id')->constrained('users')->onDelete('cascade');
            $table->foreignId('text_id')->nullable()->constrained('texts')->nullOnDelete();
            $table->text('generated_text')->nullable();
            $table->string('match_type', 10);
            $table->string('status', 15)->default('waiting');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('match_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('match_id')->constrained('matches')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->decimal('wpm', 6, 2)->nullable();
            $table->decimal('accuracy', 5, 2)->nullable();
            $table->integer('placement')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('match_participants');
        Schema::dropIfExists('matches');
    }
};
