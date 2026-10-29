<?php

namespace App\Console\Commands;

use App\Models\CostAccount;
use App\Models\FinancialBaseline;
use App\Models\GlEntry;
use App\Models\InventoryOpening;
use App\Models\MonthlyMetric;
use App\Models\SystemConfig;
use Illuminate\Console\Command;

class DashboardVerify extends Command
{
    protected $signature = 'dashboard:verify {--path= : Folder containing extracted JSON snapshot}';
    protected $description = 'Verify loaded counts against the frozen data snapshot';

    public function handle(): int
    {
        $path = $this->option('path') ?: base_path('../data_snapshot/extracted');
        $metrics = json_decode(file_get_contents($path . '/production_daily_sheets.json'), true, 512, JSON_THROW_ON_ERROR)['db']['metrics'];
        $expectedMetrics = array_sum(array_map(fn ($m) => count($m['months'] ?? []), $metrics));
        $checks = [
            'cost_accounts' => [CostAccount::count(), 163], 'financial_baselines' => [FinancialBaseline::count(), 1],
            'inventory_openings' => [InventoryOpening::count(), 9], 'system_configs' => [SystemConfig::count(), 4],
            'gl_entries' => [GlEntry::count(), 12583], 'monthly_metrics' => [MonthlyMetric::count(), $expectedMetrics],
        ];
        $failed = false;
        foreach ($checks as $name => [$actual, $expected]) { $ok = $actual === $expected; $this->line(($ok ? '<fg=green>OK</>' : '<fg=red>FAIL</>') . " {$name}: {$actual} (expected {$expected})"); $failed = $failed || !$ok; }
        foreach (GlEntry::query()->selectRaw('period, COUNT(*) as row_count, SUM(amount_reporting) as total')->groupBy('period')->orderBy('period')->get() as $row) $this->line("GL {$row->period}: {$row->row_count} rows, total {$row->total}");
        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
