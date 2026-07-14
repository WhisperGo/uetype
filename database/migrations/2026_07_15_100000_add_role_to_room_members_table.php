<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Peran member di dalam room: 'player' (pembalap, ikut klasemen/place/XP) atau
     * 'spectator' (penonton, hanya menonton live). Default 'player' supaya baris
     * lama tetap terbaca sebagai pembalap. Spectator TAK PERNAH masuk perhitungan
     * finish/place/XP maupun cap pemain -- disaring lewat Room::players()/spectators().
     */
    public function up(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->string('role')->default('player')->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
