<?php

namespace App\Console\Commands;

use App\Services\GlImportService;
use App\Services\GlParserService;
use Database\Seeders\CostAccountSeeder;
use Database\Seeders\DashboardSeeder;
use Illuminate\Console\Command;

class DashboardLoadData extends Command
{
    protected $signature = 'dashboard:load-data {--path= : Folder containing extracted JSON snapshot}';
    protected $description = 'Load the frozen dashboard data snapshot into MySQL';

    public function handle(GlParserService $parser, GlImportService $importer): int
    {
        $path = $this->option('path') ?: base_path('../data_snapshot/extracted');
        if (!is_dir($path)) { $this->error("Snapshot folder not found: {$path}"); return self::FAILURE; }
        try {
            $this->line('Step 1/3: cost accounts');
            $this->callSilent('db:seed', ['--class' => CostAccountSeeder::class, '--force' => true]);
            $this->info('  OK: cost_accounts loaded');
            $this->line('Step 2/3: dashboard reference data');
            (new DashboardSeeder($path))->run();
            $this->info('  OK: metrics, baseline, inventory, config, accruals loaded');
            $this->line('Step 3/3: GL entries');
            $parsed = $parser->parseFile($path . '/gl_data.json');
            $batch = $importer->import($parsed['rows'], 'gl_data.json', 'Frozen data snapshot 2026-10-30');
            $this->info('  OK: ' . $parsed['stats']['totalRows'] . ' GL rows, periods ' . implode(', ', $batch->months_covered));
            $this->newLine();
            $this->info('DATA LOAD COMPLETE');
            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }
    }
}
