<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop `dnf`: it can no longer be anything but false.
     *
     * The column was added (2026_07_14_090000) when a DNF that had typed something was still
     * worth recording, and it existed to keep the 999s sentinel out of the duration column.
     * The rule then changed: FinalizesRace::isValidRaceResult() rejects EVERY DNF, and a
     * history row is only written for a result that survives that gate -- so every row
     * reaching this table is a genuine finisher, and `dnf` has been a constant false ever
     * since. Nothing reads it either; Stats aggregates place/wpm/accuracy only.
     *
     * A column that can hold exactly one value is worse than no column: the next reader
     * assumes it distinguishes something, and writes a query that silently returns nothing.
     *
     * Legacy rows back-filled as dnf = true keep their null finished_time_seconds, which is
     * what the aggregates already rely on (SQL AVG/MAX skip NULL), so no statistic moves.
     */
    public function up(): void
    {
        Schema::table('multiplayer_match_history', function (Blueprint $table) {
            $table->dropColumn('dnf');
        });
    }

    /**
     * Restores the column, not the data: which historical rows were DNFs is not recoverable
     * (their null duration is the only trace left, and that is also a legitimate value).
     */
    public function down(): void
    {
        Schema::table('multiplayer_match_history', function (Blueprint $table) {
            $table->boolean('dnf')->default(false)->after('finished_time_seconds');
        });
    }
};
