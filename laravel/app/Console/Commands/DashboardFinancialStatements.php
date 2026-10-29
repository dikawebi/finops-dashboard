<?php

namespace App\Console\Commands;

use App\Services\FinancialStatementService;
use Illuminate\Console\Command;

class DashboardFinancialStatements extends Command
{
    protected $signature = 'dashboard:financial-statements {period=2026-10} {--mode=mtd}';
    protected $description = 'Build financial statement sections for a period';

    public function handle(FinancialStatementService $service): int
    {
        $result = $service->build($this->argument('period'), $this->option('mode'));
        foreach ($result['sections'] as $name => $lines) $this->line($name . ': ' . count($lines) . ' lines');
        $this->info('Financial statements built for ' . $result['period'] . ' (' . $result['mode'] . ')');
        return self::SUCCESS;
    }
}
