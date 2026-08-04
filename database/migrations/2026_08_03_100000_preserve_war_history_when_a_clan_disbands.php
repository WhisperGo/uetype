<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A finished war is a fact about BOTH clans, so one of them leaving must not erase it.
 *
 * `clan_wars` cascaded on delete from either side. Disbanding is blocked while a war is
 * Pending or Ongoing (Clans::disbandClan knows the cascade would take the opponent's live war
 * with it), but FINISHED wars were never covered: clan A disbands, every row it appears in
 * disappears, and clan B silently loses war history it earned. Worse, the Elo those wars paid
 * out stays on `clans.power` -- so the board ends up showing a clan whose power its own record
 * can no longer explain. Nothing errors; the number is just quietly wrong from then on.
 *
 * The fix keeps the row and lets the SIDE go null, with the clan's name snapshotted so the
 * history still reads as a sentence ("vs Nightfall") rather than a hole. Snapshots are written
 * by ClanWar::booted() at creation, not by each call site, so a future way of starting a war
 * cannot forget them.
 *
 * Names are copied rather than referenced on purpose: the point is to survive the clan's
 * deletion, and a snapshot that pointed at the clans table would die with it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clan_wars', function (Blueprint $table) {
            // 32 = clans.name's own length, so a snapshot can never truncate a real name.
            $table->string('challenger_name', 32)->nullable()->after('opponent_clan_id');
            $table->string('opponent_name', 32)->nullable()->after('challenger_name');
        });

        // Backfill: every existing war still has both clans, so their names are available now.
        // After this migration they may not be, which is the entire point of the column.
        DB::statement('
            UPDATE clan_wars
            LEFT JOIN clans AS challenger ON challenger.id = clan_wars.challenger_clan_id
            LEFT JOIN clans AS opponent ON opponent.id = clan_wars.opponent_clan_id
            SET clan_wars.challenger_name = challenger.name,
                clan_wars.opponent_name = opponent.name
        ');

        Schema::table('clan_wars', function (Blueprint $table) {
            $table->dropForeign(['challenger_clan_id']);
            $table->dropForeign(['opponent_clan_id']);
        });

        Schema::table('clan_wars', function (Blueprint $table) {
            $table->foreignId('challenger_clan_id')->nullable()->change();
            $table->foreignId('opponent_clan_id')->nullable()->change();
        });

        Schema::table('clan_wars', function (Blueprint $table) {
            $table->foreign('challenger_clan_id')->references('id')->on('clans')->nullOnDelete();
            $table->foreign('opponent_clan_id')->references('id')->on('clans')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('clan_wars', function (Blueprint $table) {
            $table->dropForeign(['challenger_clan_id']);
            $table->dropForeign(['opponent_clan_id']);
        });

        // Rows whose clan is already gone cannot be made NOT NULL again; they only exist
        // because of this migration, so dropping them restores the old shape honestly.
        DB::table('clan_wars')
            ->whereNull('challenger_clan_id')
            ->orWhereNull('opponent_clan_id')
            ->delete();

        Schema::table('clan_wars', function (Blueprint $table) {
            $table->foreignId('challenger_clan_id')->nullable(false)->change();
            $table->foreignId('opponent_clan_id')->nullable(false)->change();
        });

        Schema::table('clan_wars', function (Blueprint $table) {
            $table->foreign('challenger_clan_id')->references('id')->on('clans')->cascadeOnDelete();
            $table->foreign('opponent_clan_id')->references('id')->on('clans')->cascadeOnDelete();
            $table->dropColumn(['challenger_name', 'opponent_name']);
        });
    }
};
