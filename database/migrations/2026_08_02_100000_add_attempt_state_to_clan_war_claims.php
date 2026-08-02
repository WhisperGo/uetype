<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanWarStatus;
use App\Services\ClanWarModeCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clan_war_mode_claims', function (Blueprint $table) {
            // Turns a claim from a replayable ticket into a single, resumable attempt.
            //
            // "One claim, one text, one attempt" used to be enforced in exactly one place, and
            // that place was the END of the session: the conditional update on typing_result_id.
            // Between claiming and submitting the row held no state at all, so a refresh, the
            // Back button and the grid's Play button all re-mounted the engine and began a
            // genuinely new session -- new clock, new text. In Words mode the text is frozen, so
            // every repeat was practice on the exact paper about to be scored.
            //
            // The clock anchor. Written once, when the attempt is first opened, and never moved.
            // Re-entering resumes from it, so the wall time already burned is unforgeable: the
            // client never writes this column and cannot reset it by reloading.
            $table->timestamp('attempt_started_at')->nullable()->after('user_id');

            // The text actually issued for this attempt. Words already had a frozen text
            // (clan_war_fixed_texts); time and survival had none, so every mount re-rolled them
            // -- the same reroll restart() and setContentLang() are already blocked from doing.
            // Freezing here covers all three modes with one rule.
            $table->text('attempt_text')->nullable()->after('attempt_started_at');

            // Coarse resume position: percent of the issued text confirmed typed. The same
            // quantity room_members.progress_percent holds for a race, and for the same reason --
            // a reload should put the player back where they were, not at the first word. Unused
            // for survival, which restarts inside a shrinking budget rather than resuming.
            $table->unsignedTinyInteger('attempt_progress')->default(0)->after('attempt_text');
        });

        Schema::table('clan_wars', function (Blueprint $table) {
            // Per-side snapshot of how many of the 9 slots one member may claim.
            //
            // The cap was a flat 4, which silently required 3 members: a 2-member clan could
            // reach only 8 slots and could NEVER complete the grid. Worse, early finish demands
            // 9/9 from both sides, so the innocent opponent was held for the full 3 days too.
            // The cap is now max(4, ceil(9 / active members)) -- see ClanWarModeCatalog.
            //
            // Snapshotted rather than computed live because a live formula would let a clan kick
            // members mid-war to raise its own cap and concentrate every slot in one account --
            // exactly the finding (F-03) the flat cap was introduced to close.
            //
            // Nullable, and readers fall back to computing from the current roster: wars that
            // predate this migration have no snapshot, and no cap at all would be worse than a
            // slightly generous one.
            $table->unsignedTinyInteger('challenger_max_claims')->nullable()->after('opponent_power_before');
            $table->unsignedTinyInteger('opponent_max_claims')->nullable()->after('challenger_max_claims');
        });

        $this->backfillClaimCaps();
    }

    public function down(): void
    {
        Schema::table('clan_war_mode_claims', function (Blueprint $table) {
            $table->dropColumn(['attempt_started_at', 'attempt_text', 'attempt_progress']);
        });

        Schema::table('clan_wars', function (Blueprint $table) {
            $table->dropColumn(['challenger_max_claims', 'opponent_max_claims']);
        });
    }

    /**
     * Give wars that are still live a cap snapshot from their roster as it stands right now.
     *
     * Only pending/ongoing wars: nothing reads the cap of a war that already resolved. Written
     * with the query builder rather than Eloquent so a later change to the model (casts,
     * global scopes, a renamed column) cannot retroactively break a migration that has
     * already run in production.
     */
    private function backfillClaimCaps(): void
    {
        $live = DB::table('clan_wars')
            ->whereIn('status', [ClanWarStatus::Pending->value, ClanWarStatus::Ongoing->value])
            ->get(['id', 'challenger_clan_id', 'opponent_clan_id']);

        foreach ($live as $war) {
            DB::table('clan_wars')->where('id', $war->id)->update([
                'challenger_max_claims' => ClanWarModeCatalog::claimCapFor($this->activeMembers($war->challenger_clan_id)),
                'opponent_max_claims' => ClanWarModeCatalog::claimCapFor($this->activeMembers($war->opponent_clan_id)),
            ]);
        }
    }

    private function activeMembers(int $clanId): int
    {
        return DB::table('clan_members')
            ->where('clan_id', $clanId)
            ->where('status', ClanMemberStatus::Active->value)
            ->count();
    }
};
