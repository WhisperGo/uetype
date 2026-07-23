<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * Artisan command to grant or revoke admin rights on an account, which is what gates
 * the user-monitoring dashboard (see EnsureUserIsAdmin). Every user starts with
 * is_admin = false, so this is the only way to open that panel for the first time.
 */
class MakeUserAdmin extends Command
{
    protected $signature = 'user:admin {email : Email of the account to promote} {--revoke : Take admin rights away instead of granting them}';

    protected $description = 'Grant admin rights to a user by email (--revoke to take them away). Admins are the only ones who can open the user-monitoring dashboard.';

    public function handle(): int
    {
        $email = $this->argument('email');
        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email '{$email}'.");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');

        if ($user->is_admin === $grant) {
            $this->info("{$user->username} is already ".($grant ? 'an admin' : 'a regular user').'. Nothing to do.');

            return self::SUCCESS;
        }

        $user->is_admin = $grant;
        $user->save();

        $this->info($grant
            ? "{$user->username} ({$email}) is now an admin."
            : "Admin rights revoked from {$user->username} ({$email})."
        );

        return self::SUCCESS;
    }
}
