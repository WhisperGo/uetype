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
        Schema::create('clan_war_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_war_id')->constrained('clan_wars')->onDelete('cascade');
            $table->foreignId('clan_id')->constrained('clans')->onDelete('cascade');
            $table->float('points')->default(0);
            $table->integer('placement')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('clan_war_participants');
    }
};
