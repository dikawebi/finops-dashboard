<?php

namespace App\Jobs;

use App\Services\D365\D365SyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunD365Sync implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 900;
    public function __construct(public readonly string $entityKey = 'all', public readonly ?string $dateFrom = null, public readonly ?string $dateTo = null) {}
    public function handle(D365SyncService $sync): void
    {
        if (!$sync->client()->enabled()) return;
        if ($this->entityKey === 'all') { $sync->syncAll(null, $this->dateFrom, $this->dateTo); return; }
        if ($entity = $sync->entityByKey($this->entityKey)) $sync->syncEntity($entity, null, $this->dateFrom, $this->dateTo);
    }
}
