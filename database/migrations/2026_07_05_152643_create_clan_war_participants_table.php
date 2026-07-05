<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clan_war_participants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_war_id')->constrained()->onDelete('cascade');
            $table->foreignId('clan_id')->constrained()->onDelete('cascade');
            $table->unsignedInteger('total_contribution')->default(0);
            $table->unsignedInteger('placement')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['clan_war_id', 'clan_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clan_war_participants');
    }
};
