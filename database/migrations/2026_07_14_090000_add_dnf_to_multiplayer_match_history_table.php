<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Separate "did not finish the race" from "elapsed duration".
     *
     * These used to be crammed into one column: a DNF player was stored with
     * finished_time_seconds = 999 (RoomMember's sentinel value). That number then got
     * written into this permanent history as if it were a real duration, so any statistic
     * that averaged finish time was polluted -- and its sorting was only "accidentally"
     * correct as long as no race ran longer than 999 seconds.
     *
     * Now: dnf = true, finished_time_seconds = null (SQL AVG/MAX ignore NULL automatically,
     * so the statistics come out clean without an extra filter).
     */
    public function up(): void
    {
        Schema::table('multiplayer_match_history', function (Blueprint $table) {
            $table->boolean('dnf')->default(false)->after('finished_time_seconds');
        });

        // Clean up old rows that already stored the sentinel as a duration.
        DB::table('multiplayer_match_history')
            ->where('finished_time_seconds', 999)
            ->update(['dnf' => true, 'finished_time_seconds' => null]);
    }

    public function down(): void
    {
        // Restore the sentinel so the column is consistent with the old schema.
        DB::table('multiplayer_match_history')
            ->where('dnf', true)
            ->update(['finished_time_seconds' => 999]);

        Schema::table('multiplayer_match_history', function (Blueprint $table) {
            $table->dropColumn('dnf');
        });
    }
};
