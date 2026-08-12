<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

/**
 * Installs and removes a named CHECK constraint.
 *
 * Why this exists: Laravel has no check-constraint API, so the only way is raw SQL --
 * and two migrations (`messages`, `message_clears`) both needed the same one. The
 * value is mostly in drop(): removing a CHECK is NOT a one-liner on MySQL (see below),
 * and it is called four times.
 *
 * This project runs on MySQL only, so no driver branching is done here. Running the
 * migrations on another driver fails loudly, which is the intended signal.
 */
class DbCheckConstraint
{
    /**
     * @param  string  $table  The table being guarded.
     * @param  string  $name  Constraint name; also what MySQL reports when it is violated.
     * @param  string  $invariant  Boolean expression that MUST hold, written with bare
     *                             column names (e.g. "a IS NULL OR b IS NULL").
     */
    public static function enforce(string $table, string $name, string $invariant): void
    {
        DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} CHECK ({$invariant})");
    }

    /**
     * Drop the constraint named $name if it exists. Safe to call when it doesn't --
     * used when re-enforcing in a later migration so a DB that already has the old
     * constraint version doesn't hit a "duplicate constraint name" error.
     */
    public static function drop(string $table, string $name): void
    {
        // `DROP CONSTRAINT IF EXISTS` is MariaDB syntax; MySQL doesn't accept it (not
        // even through 8.4), and plain `DROP CHECK` errors when the constraint is
        // absent. So we ask information_schema first, then drop -- valid on both.
        $exists = DB::selectOne(
            'SELECT 1 AS ok FROM information_schema.TABLE_CONSTRAINTS
             WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = ?',
            [$table, $name]
        );

        if ($exists) {
            DB::statement("ALTER TABLE {$table} DROP CHECK {$name}");
        }
    }
}
