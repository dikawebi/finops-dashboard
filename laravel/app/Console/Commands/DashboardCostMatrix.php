<?php

namespace App\Console\Commands;

use App\Services\CostMatrixService;
use Illuminate\Console\Command;

class DashboardCostMatrix extends Command
{
    protected $signature = 'dashboard:cost-matrix {period=2026-10} {--mode=mtd}';
    protected $description = 'Calculate Report Cost matrix for a period (P3 engine smoke test)';

    public function handle(CostMatrixService $service): int
    {
        $result = $service->build($this->argument('period'), $this->option('mode'));
        $this->table(['Metric', 'Value'], [['Rows', count($result['rows'])], ['Periods', implode(', ', $result['periods'])], ['ALL Cost', $result['grandTotal']['allCost']], ['Total COGP', $result['grandTotal']['totalCOGP']], ['Total Expense', $result['grandTotal']['totalExpense']]]);
        return self::SUCCESS;
    }
}
