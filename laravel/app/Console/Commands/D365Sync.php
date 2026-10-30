<?php

namespace App\Console\Commands;

use App\Jobs\RunD365Sync;
use App\Services\D365\D365SyncService;
use Illuminate\Console\Command;

class D365Sync extends Command
{
    protected $signature = 'd365:sync {--entity=all} {--limit=} {--date-from=} {--date-to=} {--queued}';
    protected $description = 'Sync data entity OData D365 ke staging';
    public function handle(D365SyncService $sync): int
    {
        if (!$sync->client()->enabled()) { $this->warn('D365 belum aktif atau credential belum lengkap.'); return self::SUCCESS; }
        $entityKey = (string) $this->option('entity');
        if ($entityKey !== 'all' && !$sync->entityByKey($entityKey)) { $this->error('Entity tidak dikenal.'); return self::FAILURE; }
        if ($this->option('queued')) { RunD365Sync::dispatch($entityKey, $this->option('date-from'), $this->option('date-to')); $this->info('Sync masuk queue.'); return self::SUCCESS; }
        $limit = $this->option('limit') !== null ? max(1, (int) $this->option('limit')) : null;
        $rows = $entityKey === 'all' ? $sync->syncAll($limit, $this->option('date-from'), $this->option('date-to')) : [$sync->syncEntity($sync->entityByKey($entityKey), $limit, $this->option('date-from'), $this->option('date-to'))];
        foreach ($rows as $row) $this->line(sprintf('[%s] %s: %d rows', $row['status'], $row['entity']['key'], $row['fetched'] ?? 0));
        return self::SUCCESS;
    }
}
