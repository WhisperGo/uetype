<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Circular FK clans.leader_id <-> users.clan_id (blueprint 6.7):
     * kedua tabel sudah dibuat tanpa constraint, constraint ditambahkan di sini.
     */
    public function up(): void
    {
        Schema::table('clans', function (Blueprint $table) {
            $table->foreign('leader_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreign('clan_id')->references('id')->on('clans')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['clan_id']);
        });

        Schema::table('clans', function (Blueprint $table) {
            $table->dropForeign(['leader_id']);
        });
    }
};
