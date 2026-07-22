<?php

use App\Support\DbCheckConstraint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * DB-level "exactly one target set" guard for `messages` and `message_clears`: a DM
     * sets recipient_id/other_user_id, clan chat sets clan_id, never both and never neither.
     *
     * Why it stands alone at the end, not in the table-creating migration:
     *
     * 1. The syntax differs per driver. `ALTER TABLE ... ADD CONSTRAINT ... CHECK` is only
     *    understood by MySQL; on sqlite the migration blows up and kills the WHOLE suite --
     *    yet .env.example defaults to sqlite. DbCheckConstraint handles the branching (CHECK
     *    on MySQL, a trigger on sqlite).
     *
     * 2. On sqlite, adding a FOREIGN KEY to an existing table forces Laravel to REBUILD that
     *    table, and the rebuild DROPS any trigger attached to it. `messages` still gets
     *    `reply_to_id` (with its FK) added three migrations after creation -- so a trigger
     *    installed in the table-creating migration would silently vanish. MySQL isn't
     *    affected, so this pitfall appears ONLY on sqlite and is easy to miss.
     *
     * Placing it after all structural changes closes both at once.
     */
    public function up(): void
    {
        // drop first: a DB that already has the constraint from an old version of this
        // migration won't hit "duplicate constraint name".
        DbCheckConstraint::drop('messages', 'messages_exactly_one_target');
        DbCheckConstraint::enforce(
            'messages',
            'messages_exactly_one_target',
            '(recipient_id IS NOT NULL AND clan_id IS NULL) OR (recipient_id IS NULL AND clan_id IS NOT NULL)',
            ['recipient_id', 'clan_id'],
        );

        DbCheckConstraint::drop('message_clears', 'message_clears_exactly_one_target');
        DbCheckConstraint::enforce(
            'message_clears',
            'message_clears_exactly_one_target',
            '(other_user_id IS NOT NULL AND clan_id IS NULL) OR (other_user_id IS NULL AND clan_id IS NOT NULL)',
            ['other_user_id', 'clan_id'],
        );
    }

    public function down(): void
    {
        DbCheckConstraint::drop('messages', 'messages_exactly_one_target');
        DbCheckConstraint::drop('message_clears', 'message_clears_exactly_one_target');
    }
};
