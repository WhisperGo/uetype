<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Enforces a CHECK constraint at the DB level, branching the mechanism per driver
 * while keeping the same invariant.
 *
 * Why this exists: Laravel has no check-constraint API at all, so the only way is
 * raw SQL -- and that raw SQL differs per driver. Two migrations (`messages`,
 * `message_clears`) both wrote a bare `ALTER TABLE ... ADD CONSTRAINT ... CHECK`,
 * syntax that ONLY MySQL understands. On sqlite both blew up and killed the ENTIRE
 * suite -- yet `.env.example` defaults to sqlite, so every new developer hit it on
 * the first step of setup.
 *
 * Unifying the syntax is impossible -- sqlite only accepts CHECK during CREATE
 * TABLE, there is no ADD CONSTRAINT. So sqlite uses triggers, which enforce the
 * same thing a different way.
 *
 * IMPORTANT -- MIGRATION ORDER: on sqlite, adding a FOREIGN KEY to an existing
 * table forces Laravel to REBUILD the table, and that rebuild DROPS any trigger
 * attached to it. So enforce() must be called from a migration that runs AFTER the
 * table's last structural change, not from the migration that creates it. This
 * actually happened: the `messages` trigger was created in the table's migration,
 * then silently vanished when `reply_to_id` (and its FK) was added three migrations
 * later -- MySQL is unaffected, so the gap only showed up on sqlite.
 */
class DbCheckConstraint
{
    /**
     * @param  string  $table  The table being guarded.
     * @param  string  $name  Constraint/trigger name; also used as the abort message.
     * @param  string  $invariant  Boolean expression that MUST hold, written with bare
     *                             column names (e.g. "a IS NULL OR b IS NULL").
     * @param  string[]  $columns  Columns referenced in $invariant. Required for sqlite:
     *                             the trigger references columns via the NEW prefix.
     */
    public static function enforce(string $table, string $name, string $invariant, array $columns): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$invariant})");

            return;
        }

        if ($driver === 'sqlite') {
            // Longest first: prevents "clan_id" from being replaced inside another
            // column name that contains it as a substring.
            usort($columns, fn ($a, $b) => strlen($b) <=> strlen($a));

            $scoped = str_replace(
                $columns,
                array_map(fn ($c) => "NEW.{$c}", $columns),
                $invariant
            );

            // INSERT and UPDATE need SEPARATE triggers: an insert-only trigger would
            // let a valid row be updated into a violating state.
            foreach (['insert', 'update'] as $event) {
                DB::statement(
                    "CREATE TRIGGER {$name}_{$event}
                     BEFORE ".strtoupper($event)." ON {$table}
                     FOR EACH ROW WHEN NOT ({$scoped})
                     BEGIN SELECT RAISE(ABORT, '{$name}'); END"
                );
            }

            return;
        }

        // Other drivers: skip silently. Better to let the table be created than to
        // fail the migration outright -- the invariant is still enforced in the app.
    }

    /**
     * Drop the guard named $name if it exists. Safe to call when it doesn't --
     * used when re-enforcing in a later migration so a DB that already has the old
     * constraint version doesn't hit a "duplicate constraint name" error.
     */
    public static function drop(string $table, string $name): void
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql' || $driver === 'mariadb') {
            // `DROP CONSTRAINT IF EXISTS` is MariaDB syntax; MySQL doesn't accept it
            // (not even through 8.4). So we ask information_schema whether it exists
            // first, then drop it -- an approach valid on both.
            $exists = DB::selectOne(
                'SELECT 1 AS ok FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
                [$table, $name]
            );

            if ($exists) {
                DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
            }

            return;
        }

        if ($driver === 'sqlite') {
            foreach (['insert', 'update'] as $event) {
                DB::statement("DROP TRIGGER IF EXISTS {$name}_{$event}");
            }
        }
    }
}
