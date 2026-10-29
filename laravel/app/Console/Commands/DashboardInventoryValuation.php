<?php

namespace App\Console\Commands;

use App\Services\InventoryValuationService;
use Illuminate\Console\Command;

class DashboardInventoryValuation extends Command
{
    protected $signature = 'dashboard:inventory-valuation {period=2026-10}';
    protected $description = 'Calculate inventory quantity, value, and cost per MT';

    public function handle(InventoryValuationService $service): int
    {
        $result = $service->build($this->argument('period'));
        if (isset($result['stages'])) {
            $this->table(['Stage', 'Location', 'Rows'], array_map(fn ($stage) => [$stage['name'], $stage['location'], count($stage['rows'] ?? [])], $result['stages']));
        } else {
            $this->table(['Location', 'Qty MT', 'Value USD', 'Cost/MT'], array_map(fn ($row) => [$row['location'], round($row['qtyMT'], 6), round($row['valueUSD'], 6), $row['costPerMT'] === null ? 'N/A' : round($row['costPerMT'], 6)], $result['locations']));
        }
        return self::SUCCESS;
    }
}
