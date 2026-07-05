<?php

namespace App\Console\Commands;

use App\Services\ClanWarResolver;
use Illuminate\Console\Command;

class ResolveClanWars extends Command
{
    protected $signature = 'clan-war:resolve';

    protected $description = 'Kadaluarsakan tantangan Clan War yang belum di-accept dan tutup war yang sudah lewat waktunya. Jalur manual/testing -- alur normal sudah dipicu on-the-fly saat halaman Clan War dibuka.';

    public function handle(ClanWarResolver $resolver): int
    {
        $resolver->resolveDue();

        $this->info('Clan wars resolved.');

        return self::SUCCESS;
    }
}
