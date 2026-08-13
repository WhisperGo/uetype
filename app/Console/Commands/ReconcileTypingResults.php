<?php

namespace App\Console\Commands;

use App\Services\AutomaticResultResolver;
use Illuminate\Console\Command;

class ReconcileTypingResults extends Command
{
    protected $signature = 'typing:reconcile {--user= : Reconcile one user id only}';

    protected $description = 'Automatically resolve corroborated pending typing results.';

    public function handle(AutomaticResultResolver $resolver): int
    {
        $userId = $this->option('user');
        $count = $resolver->reconcile($userId === null ? null : (int) $userId);

        $this->info("Promoted {$count} pending result(s) to clear.");

        return self::SUCCESS;
    }
}
