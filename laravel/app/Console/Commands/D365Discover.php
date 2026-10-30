<?php

namespace App\Console\Commands;

use App\Services\D365\D365SyncService;
use Illuminate\Console\Command;

class D365Discover extends Command
{
    protected $signature = 'd365:discover {--entity=all}';
    protected $description = 'Bekijk field names van entity OData D365';
    public function handle(D365SyncService $sync): int
    {
        if (!$sync->client()->enabled()) { $this->error('D365 belum aktif.'); return self::FAILURE; }
        $requested = (string) $this->option('entity');
        $entities = $requested === 'all' ? D365SyncService::ENTITIES : array_values(array_filter(D365SyncService::ENTITIES, fn (array $item): bool => $item['key'] === $requested));
        foreach ($entities as $entity) {
            try {
                foreach ($sync->client()->streamEntity($entity['entity'], null, null, null, 1, 1) as $row) { $this->line($entity['key'].': '.implode(', ', array_keys($row))); break; }
            } catch (\Throwable $exception) { $this->error($entity['key'].': '.$exception->getMessage()); }
        }
        return self::SUCCESS;
    }
}
