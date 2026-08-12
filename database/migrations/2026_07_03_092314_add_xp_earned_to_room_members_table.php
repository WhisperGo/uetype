<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            // EXP the player earned from this match. Null while the race is running; filled
            // ONCE in finalizeRace() as a "EXP already awarded" marker (prevents double-award)
            // and also the value to display in the result panel.
            $table->integer('xp_earned')->nullable()->after('place');
        });
    }

    public function down(): void
    {
        Schema::table('room_members', function (Blueprint $table) {
            $table->dropColumn('xp_earned');
        });
    }
};
