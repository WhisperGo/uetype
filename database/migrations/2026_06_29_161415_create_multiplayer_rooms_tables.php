<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Main room table (lobby)
        Schema::create('rooms', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique(); // Unique code like 'A3F9K2'
            $table->foreignId('host_id')->constrained('users')->onDelete('cascade');
            $table->string('status')->default('waiting'); // waiting, racing, finished
            $table->text('text_to_type'); // The same race paragraph for every player
            $table->timestamp('countdown_started_at')->nullable(); // Records when the 15s sudden death started
            $table->timestamps();
        });

        // 2. Member pivot table inside the room
        Schema::create('room_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('room_id')->constrained('rooms')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->boolean('is_ready')->default(false); // Participant ready status
            $table->integer('progress_percent')->default(0); // Live typing progress 0-100%
            $table->integer('wpm')->default(0);
            $table->decimal('accuracy', 5, 2)->default(0.00);
            $table->integer('finished_time_seconds')->nullable(); // Elapsed time if they finished
            $table->integer('place')->nullable(); // 1st, 2nd, 3rd, etc.
            $table->timestamps();

            $table->unique(['room_id', 'user_id']); // Ensures one user can't join the same room twice
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('room_members');
        Schema::dropIfExists('rooms');
    }
};
