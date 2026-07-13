<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether a finished player's result passed server validation and was written
     * to multiplayer_match_history. Null until finalized; false = rejected by
     * anti-cheat (kept out of stats, flagged on the result screen).
     */
    public function up(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->boolean('result_recorded')->nullable()->after('xp_earned');
        });
    }

    public function down(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->dropColumn('result_recorded');
        });
    }
};
