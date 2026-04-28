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
        Schema::create('match_participants', function (Blueprint $table) {
            $table->id();
            $table->float('wpm', 10, 2)->default(0);
            $table->float('accuracy', 10, 2)->default(0);
            $table->integer('placement')->default(0);
            $table->integer('elo_change')->default(0);
            $table->string('connection_status')->default('disconnected');

            $table->foreignId('match_id')->constrained('matches')->onDelete('cascade');
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('match_participants');
    }
};
