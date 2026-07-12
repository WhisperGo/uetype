<?php

namespace App\Console\Commands;

use App\Services\ClanWarResolver;
use Illuminate\Console\Command;

/**
 * Artisan command to settle due Clan Wars manually (expire unaccepted challenges,
 * close wars past their time). A manual/testing entry point — the normal flow is
 * triggered on-the-fly when the Clan War page loads (see ClanWarResolver).
 */
class ResolveClanWars extends Command
{
    protected $signature = 'clan-war:resolve';

    protected $description = 'Expire unaccepted Clan War challenges and close wars past their time. Manual/testing path -- the normal flow is triggered on-the-fly when the Clan War page opens.';

    public function handle(ClanWarResolver $resolver): int
    {
        $resolver->resolveDue();

        $this->info('Clan wars resolved.');

        return self::SUCCESS;
    }
}
