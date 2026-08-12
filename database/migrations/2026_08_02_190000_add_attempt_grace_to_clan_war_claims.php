<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clan_war_mode_claims', function (Blueprint $table) {
            // Whether this attempt has already spent its one countdown grace.
            //
            // COUNTDOWN_GRACE_SECONDS exists to cover a page load, and a page load happens once
            // per mount -- so the allowance was being handed out once per mount too, because
            // remainingSeconds() simply added it every time it was called. Ten refreshes on a
            // 60-second slot bought 100 seconds of grace, which is the exact reroll the whole
            // attempt system exists to price.
            //
            // A boolean rather than a mount counter: the question the clock has to answer is
            // "has this attempt been paid for its load yet", not "how many times was it
            // loaded". The first mount is the one that genuinely cannot type during page load;
            // every later one is a resume, and a resumer has already read the text.
            $table->boolean('attempt_grace_used')->default(false)->after('attempt_chars');
        });
    }

    public function down(): void
    {
        Schema::table('clan_war_mode_claims', function (Blueprint $table) {
            $table->dropColumn('attempt_grace_used');
        });
    }
};
