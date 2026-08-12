<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clan_war_mode_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_war_id')->constrained()->onDelete('cascade');
            $table->foreignId('clan_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('mode', 20);
            $table->string('mode_config', 10);
            $table->foreignId('typing_result_id')->nullable()->constrained()->onDelete('cascade');
            $table->decimal('points', 8, 2)->nullable();
            $table->timestamp('claimed_at');
            $table->timestamps();

            $table->unique(['clan_war_id', 'mode', 'mode_config', 'clan_id'], 'clan_war_mode_claims_unique_slot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clan_war_mode_claims');
    }
};
