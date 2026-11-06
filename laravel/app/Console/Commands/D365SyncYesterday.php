<?php

namespace App\Console\Commands;

use App\Jobs\RunD365Sync;
use App\Services\D365\D365SyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class D365SyncYesterday extends Command
{
    protected $signature = 'd365:sync-yesterday';
    protected $description = 'Queue D365 sync for the previous calendar day';

    public function handle(D365SyncService $sync): int
    {
        if (!$sync->client()->enabled()) {
            $this->warn('D365 is disabled or credentials are incomplete.');
            return self::SUCCESS;
        }

        $day = Carbon::now('Asia/Bangkok')->subDay()->toDateString();
        RunD365Sync::dispatch('all', $day, $day);
        $this->info("Queued D365 sync for {$day}.");
        return self::SUCCESS;
    }
}
