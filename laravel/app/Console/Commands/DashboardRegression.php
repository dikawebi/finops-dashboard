<?php

namespace App\Console\Commands;

use App\Services\CostMatrixService;
use Illuminate\Console\Command;

class DashboardRegression extends Command
{
    protected $signature = 'dashboard:regression {--path= : Folder containing baseline JSON files} {--period= : Check one YYYY-MM period only} {--mode= : Check one mode (mtd or ytd) only} {--tolerance=0.000001}';
    protected $description = 'Compare calculated dashboard output with the frozen baseline dump';

    public function handle(CostMatrixService $service): int
    {
        $path = $this->option('path') ?: base_path('../baseline/reference_dump');
        if (!is_dir($path)) {
            $this->error("Baseline folder not found: {$path}");
            return self::FAILURE;
        }

        $files = glob($path . '/cost-matrix_????-??_*.json') ?: [];
        $files = array_values(array_filter($files, function (string $file): bool {
            if ($this->option('period') && !str_contains(basename($file), $this->option('period'))) return false;
            if ($this->option('mode') && !str_ends_with(basename($file), '_' . $this->option('mode') . '.json')) return false;
            return true;
        }));
        sort($files);
        if (!$files) { $this->warn('No cost-matrix baseline files matched.'); return self::FAILURE; }

        $failed = false;
        foreach ($files as $file) {
            $name = basename($file);
            if (!preg_match('/cost-matrix_(\d{4}-\d{2})_(mtd|ytd)\.json$/', $name, $match)) continue;
            $expected = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR)['data'];
            $actual = $service->build($match[1], $match[2]);
            $diff = $this->firstDiff($expected, $actual, '$', (float) $this->option('tolerance'));
            if ($diff === null) $this->line("<fg=green>OK</> {$name}");
            else { $this->line("<fg=red>FAIL</> {$name}: {$diff}"); $failed = true; }
        }
        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function firstDiff(mixed $expected, mixed $actual, string $path, float $tolerance): ?string
    {
        if (is_numeric($expected) && is_numeric($actual)) {
            return abs((float) $expected - (float) $actual) <= $tolerance ? null : "{$path} expected {$expected}, got {$actual}";
        }
        if (is_array($expected) && is_array($actual)) {
            foreach ($expected as $key => $value) {
                if (!array_key_exists($key, $actual)) return "{$path}.{$key} missing from actual output";
                if (($diff = $this->firstDiff($value, $actual[$key], "{$path}.{$key}", $tolerance)) !== null) return $diff;
            }
            foreach ($actual as $key => $_) if (!array_key_exists($key, $expected)) return "{$path}.{$key} unexpected in actual output";
            return null;
        }
        return $expected === $actual ? null : "{$path} expected " . json_encode($expected) . ", got " . json_encode($actual);
    }
}
