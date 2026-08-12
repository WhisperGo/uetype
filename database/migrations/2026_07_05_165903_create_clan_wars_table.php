<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clan_wars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('challenger_clan_id')->constrained('clans')->onDelete('cascade');
            $table->foreignId('opponent_clan_id')->constrained('clans')->onDelete('cascade');
            $table->string('status', 15)->default('pending');
            $table->integer('challenger_power_before')->nullable();
            $table->integer('opponent_power_before')->nullable();
            $table->integer('challenger_power_delta')->nullable();
            $table->integer('opponent_power_delta')->nullable();
            $table->string('result', 10)->nullable();
            $table->timestamp('accept_deadline_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clan_wars');
    }
};
