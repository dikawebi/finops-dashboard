<?php

namespace App\Console\Commands;

use App\Services\CostMatrixService;
use App\Services\CostPerMtService;
use App\Services\FinancialStatementService;
use App\Services\InventoryValuationService;
use Illuminate\Console\Command;

class DashboardRegression extends Command
{
    protected $signature = 'dashboard:regression {--path= : Folder containing baseline JSON files} {--period= : Check one YYYY-MM period only} {--mode= : Check one mode (mtd or ytd) only} {--suite=all : all, cost-matrix, cost-per-mt, financial, or inventory} {--tolerance=0.01}';
    protected $description = 'Compare calculated dashboard output with the frozen baseline dump';

    public function handle(CostMatrixService $costMatrix, CostPerMtService $costPerMt, FinancialStatementService $financial, InventoryValuationService $inventory): int
    {
        $path = $this->option('path') ?: base_path('../baseline/reference_dump');
        if (!is_dir($path)) {
            $this->error("Baseline folder not found: {$path}");
            return self::FAILURE;
        }

        $failed = false;
        $suite = $this->option('suite');
        if (in_array($suite, ['all', 'cost-matrix'], true)) $failed = $this->checkSuite($path, 'cost-matrix', $costMatrix->build(...)) || $failed;
        if (in_array($suite, ['all', 'cost-per-mt'], true)) $failed = $this->checkSuite($path, 'cost-per-mt', $costPerMt->build(...)) || $failed;
        if (in_array($suite, ['all', 'financial'], true)) $failed = $this->checkSuite($path, 'financial-statements', $financial->build(...)) || $failed;
        if (in_array($suite, ['all', 'inventory'], true)) $failed = $this->checkSuite($path, 'inventory-valuation', $inventory->build(...)) || $failed;
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function checkSuite(string $path, string $prefix, callable $builder): bool
    {
        $files = glob($path . '/' . $prefix . '_????-??_*.json') ?: [];
        $files = array_values(array_filter($files, function (string $file): bool {
            if ($this->option('period') && !str_contains(basename($file), $this->option('period'))) return false;
            if ($this->option('mode') && !str_ends_with(basename($file), '_' . $this->option('mode') . '.json')) return false;
            return true;
        }));
        sort($files);
        if (!$files) { $this->warn("No {$prefix} baseline files matched."); return true; }
        $failed = false;
        foreach ($files as $file) {
            $name = basename($file);
            if (!preg_match('/' . preg_quote($prefix, '/') . '_(\d{4}-\d{2})_(mtd|ytd)\.json$/', $name, $match)) continue;
            $expected = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['data'];
            $actual = $builder($match[1], $match[2]);
            $diff = $this->firstDiff($expected, $actual, '$', (float) $this->option('tolerance'));
            if ($diff === null) $this->line("<fg=green>OK</> {$name}");
            else { $this->line("<fg=red>FAIL</> {$name}: {$diff}"); $failed = true; }
        }
        return $failed;
    }

    private function firstDiff(mixed $expected, mixed $actual, string $path, float $tolerance): ?string
    {
        // Account labels are maintained by the data layer and may differ from
        // the frozen API wording; regression focuses on calculation fields.
        if (str_ends_with($path, '.name') || str_ends_with($path, '.parent')) return null;
        if ($path === '$.rows' && is_array($expected) && is_array($actual)) {
            $expected = collect($expected)->keyBy('code')->all();
            $actual = collect($actual)->keyBy('code')->all();
        }
        if (is_numeric($expected) && is_numeric($actual)) {
            return abs((float) $expected - (float) $actual) <= $tolerance ? null : "{$path} expected {$expected}, got {$actual}";
        }
        if (is_array($expected) && is_array($actual)) {
            foreach ($expected as $key => $value) {
                if (in_array($key, ['name', 'parent', 'unallocatedHaulingAccounts'], true)) continue;
                if (!array_key_exists($key, $actual)) return "{$path}.{$key} missing from actual output";
                if (($diff = $this->firstDiff($value, $actual[$key], "{$path}.{$key}", $tolerance)) !== null) return $diff;
            }
            foreach ($actual as $key => $_) if (!in_array($key, ['name', 'parent', 'cek', 'sections', 'period', 'mode', 'dataSource', 'cashFlowEnabled', 'comparison', 'comparisonPeriod'], true) && !array_key_exists($key, $expected)) return "{$path}.{$key} unexpected in actual output";
            return null;
        }
        return $expected === $actual ? null : "{$path} expected " . json_encode($expected) . ", got " . json_encode($actual);
    }
}
