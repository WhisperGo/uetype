<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clan_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clan_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->string('role', 10)->default('member');
            $table->string('status', 15)->default('pending');
            $table->timestamps();

            $table->unique(['clan_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clan_members');
    }
};
