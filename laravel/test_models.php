<?php

/**
 * P1 verification: pastikan 14 model Eloquent dapat load & count tabelnya.
 * Jalankan: php test_models.php  (dari folder laravel/)
 */

require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

$models = [
    'GlEntry',
    'GlImportBatch',
    'MonthlyMetric',
    'DailyProduction',
    'DbCostSummary',
    'SystemConfig',
    'FinancialBaseline',
    'InventoryOpening',
    'InventoryMovement',
    'Accrual',
    'AccrualHistory',
    'CostAccount',
    'CostVarianceRemark',
    'DbCostRemark',
];

echo 'DB connection: ' . DB::connection()->getName() . ' | database: ' . DB::connection()->getDatabaseName() . PHP_EOL;
echo str_repeat('-', 72) . PHP_EOL;
printf("%-22s %-24s %10s %s\n", 'MODEL', 'TABLE', 'COUNT', 'STATUS');
echo str_repeat('-', 72) . PHP_EOL;

$fail = 0;
foreach ($models as $m) {
    $class = "App\\Models\\{$m}";
    try {
        $model = new $class();
        $table = $model->getTable();
        $tableExists = Schema::hasTable($table);
        $count = $tableExists ? $class::count() : null;
        $status = $tableExists ? 'OK' : 'TABLE MISSING';
        if (!$tableExists) {
            $fail++;
        }
        printf("%-22s %-24s %10s %s\n", $m, $table, var_export($count, true), $status);
    } catch (Throwable $e) {
        $fail++;
        printf("%-22s %-24s %10s ERROR: %s\n", $m, '-', '-', substr($e->getMessage(), 0, 60));
    }
}

echo str_repeat('-', 72) . PHP_EOL;
echo $fail === 0 ? "RESULT: ALL " . count($models) . " MODELS OK\n" : "RESULT: {$fail} FAILURE(S)\n";
exit($fail === 0 ? 0 : 1);
