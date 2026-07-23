<?php

use App\Support\DbCheckConstraint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * DB-level "exactly one target set" guard for `messages` and `message_clears`: a DM
     * sets recipient_id/other_user_id, clan chat sets clan_id, never both and never neither.
     *
     * It lives in its own late migration rather than in the two table-creating ones so
     * both invariants -- the same shape, on tables created several migrations apart --
     * are defined together and stay readable side by side. Each guard is dropped before
     * being (re)created, so this migration is safe to re-run against a database that
     * already carries an earlier version of the constraint.
     */
    public function up(): void
    {
        DbCheckConstraint::drop('messages', 'messages_exactly_one_target');
        DbCheckConstraint::enforce(
            'messages',
            'messages_exactly_one_target',
            '(recipient_id IS NOT NULL AND clan_id IS NULL) OR (recipient_id IS NULL AND clan_id IS NOT NULL)',
        );

        DbCheckConstraint::drop('message_clears', 'message_clears_exactly_one_target');
        DbCheckConstraint::enforce(
            'message_clears',
            'message_clears_exactly_one_target',
            '(other_user_id IS NOT NULL AND clan_id IS NULL) OR (other_user_id IS NULL AND clan_id IS NOT NULL)',
        );
    }

    public function down(): void
    {
        DbCheckConstraint::drop('messages', 'messages_exactly_one_target');
        DbCheckConstraint::drop('message_clears', 'message_clears_exactly_one_target');
    }
};
