<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clan_war_mode_claims', function (Blueprint $table) {
            // A ledger of the work an attempt has already banked, so a refresh stops changing
            // what the run is WORTH.
            //
            // The resume added by the previous migration restored a player's POSITION, and the
            // client then credited those restored characters to its keystroke counters. But the
            // client's clock starts on the first keystroke AFTER the reload, so the numerator
            // spanned the whole attempt while the denominator spanned only the last session:
            // WPM inflated, and since war points are ceiling x (wpm / 150), a refresh bought
            // points. The restored characters were also all marked CORRECT, so every mistake
            // made before the reload vanished and the accuracy multiplier (0.5-1.0x) was
            // laundered along with it.
            //
            // Both leaks are the same shape -- characters credited over time that was not --
            // and both close by measuring instead of guessing. Each session reports its own
            // typing time and keystroke counts; the server sums them. The old compensation
            // (ClanWarAttempt::GRACE_SECONDS subtracted from the anchor) was a guess at how
            // much wall clock to forgive, and it forgave real typing time.
            //
            // LIVE is the session running right now, overwritten by each ping. CARRIED is every
            // session before it, sealed on the next page load. They are separate because a new
            // session's counters restart at zero, which is indistinguishable from a rewind
            // unless the two are kept apart -- and because the submission at the end already
            // carries the live session in full, so summing both would double-count it.
            $table->unsignedBigInteger('attempt_carried_ms')->default(0)->after('attempt_text');
            $table->unsignedInteger('attempt_carried_correct_chars')->default(0)->after('attempt_carried_ms');
            $table->unsignedInteger('attempt_carried_total_chars')->default(0)->after('attempt_carried_correct_chars');

            $table->unsignedBigInteger('attempt_live_ms')->default(0)->after('attempt_carried_total_chars');
            $table->unsignedInteger('attempt_live_correct_chars')->default(0)->after('attempt_live_ms');
            $table->unsignedInteger('attempt_live_total_chars')->default(0)->after('attempt_live_correct_chars');

            // Resume position in CHARACTERS, replacing the percent it supersedes.
            //
            // Percent was borrowed from room_members.progress_percent, where it is only ever
            // drawn as a bar. Here it is arithmetic: on a ~280-character Words text one percent
            // is three characters, and the client rounds down AGAIN to a whole word boundary,
            // so the position was quantised twice before anyone read it. Characters cost the
            // same to store and round once, in the one place that must round at all -- the
            // client, which has to land on a word boundary to be typeable.
            $table->unsignedInteger('attempt_chars')->default(0)->after('attempt_live_total_chars');
        });

        Schema::table('clan_war_mode_claims', function (Blueprint $table) {
            $table->dropColumn('attempt_progress');
        });
    }

    public function down(): void
    {
        Schema::table('clan_war_mode_claims', function (Blueprint $table) {
            $table->unsignedTinyInteger('attempt_progress')->default(0)->after('attempt_text');
        });

        Schema::table('clan_war_mode_claims', function (Blueprint $table) {
            $table->dropColumn([
                'attempt_carried_ms',
                'attempt_carried_correct_chars',
                'attempt_carried_total_chars',
                'attempt_live_ms',
                'attempt_live_correct_chars',
                'attempt_live_total_chars',
                'attempt_chars',
            ]);
        });
    }
};
