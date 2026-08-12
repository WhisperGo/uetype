<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A member's role in the room: 'player' (racer, counts toward standings/place/XP) or
     * 'spectator' (watches live only). Defaults to 'player' so old rows still read as
     * racers. A spectator NEVER enters the finish/place/XP calculation or the player cap --
     * they're filtered out via Room::players()/spectators().
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
