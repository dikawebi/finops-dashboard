<?php

namespace App\Jobs;

use App\Services\D365\D365SyncService;
use App\Services\D365\D365AllocationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class RunD365Sync implements ShouldQueue
{
    use Queueable;
    public int $tries = 1;
    public int $timeout = 900;
    public function __construct(public readonly string $entityKey = 'all', public readonly ?string $dateFrom = null, public readonly ?string $dateTo = null, public readonly bool $forceFull = false) {}
    public function handle(D365SyncService $sync): void
    {
        if (!$sync->client()->enabled()) return;
        $results = $this->entityKey === 'all'
            ? $sync->syncAll(null, $this->dateFrom, $this->dateTo, $this->forceFull)
            : (($entity = $sync->entityByKey($this->entityKey)) ? [$sync->syncEntity($entity, null, $this->dateFrom, $this->dateTo, $this->forceFull)] : []);
        $glResult = collect($results)->firstWhere('entity.key', 'gl_entries') ?? collect($results)->firstWhere('entity', 'gl_entries');
        $mainAccountsResult = collect($results)->firstWhere('entity.key', 'main_accounts') ?? collect($results)->firstWhere('entity', 'main_accounts');
        if (!$sync->stopRequested() && ($mainAccountsResult['status'] ?? null) === 'success') {
            app(D365AllocationService::class)->syncAccountHierarchy();
        }
        if (!$sync->stopRequested() && $glResult && ($glResult['status'] ?? null) === 'success') {
            app(D365AllocationService::class)->importStaging($this->dateFrom, $this->dateTo);
        }
    }
}
