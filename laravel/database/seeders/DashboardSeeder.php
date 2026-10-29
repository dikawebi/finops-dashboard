<?php

namespace Database\Seeders;

use App\Models\Accrual;
use App\Models\DbCostSummary;
use App\Models\FinancialBaseline;
use App\Models\InventoryOpening;
use App\Models\MonthlyMetric;
use App\Models\SystemConfig;
use Illuminate\Database\Seeder;

class DashboardSeeder extends Seeder
{
    public function __construct(private readonly ?string $dataPath = null) {}

    public function run(): void
    {
        $path = $this->dataPath ?: base_path('../data_snapshot/extracted');
        $this->seedMetrics($path . '/production_daily_sheets.json');
        $this->seedBaseline($path . '/financial_baseline.json');
        $this->seedInventory($path . '/inventory_model.json');
        $this->seedConfig($path . '/config.json');
        $this->seedAccruals($path . '/accruals.json');
        $this->seedDbCostSummary($path . '/automatic_accrual_history.json');
    }

    private function read(string $file): array { return json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR); }
    private function seedMetrics(string $file): void
    {
        foreach (($this->read($file)['db']['metrics'] ?? []) as $key => $metric) {
            foreach (($metric['months'] ?? []) as $period => $value) {
                MonthlyMetric::updateOrCreate(['metric_key' => $key, 'period' => $period], ['label' => $metric['label'] ?? $key, 'unit' => $metric['unit'] ?? null, 'value' => $value]);
            }
        }
    }
    private function seedBaseline(string $file): void
    {
        $d = $this->read($file);
        FinancialBaseline::updateOrCreate(['version' => (int) ($d['version'] ?? 1)], [
            'company' => $d['company'] ?? '', 'currency' => $d['currency'] ?? 'USD', 'base_period' => $d['basePeriod'],
            'gl_start_period' => $d['glStartPeriod'], 'year' => $d['year'], 'comparative_year' => $d['comparativeYear'] ?? null,
            'sections' => $d['sections'] ?? [], 'accounts' => $d['accounts'] ?? [], 'policy' => $d['policy'] ?? [],
            'cash_flow_enabled' => (bool) ($d['cashFlowEnabled'] ?? false),
        ]);
    }
    private function seedInventory(string $file): void
    {
        $d = $this->read($file);
        foreach (($d['openingBalances'] ?? []) as $location => $item) {
            $qty = (float) ($item['qtyMT'] ?? 0); $value = (float) ($item['valueUSD'] ?? 0);
            InventoryOpening::updateOrCreate(['location' => $location], [
                'location_label' => $location, 'period' => $d['startPeriod'], 'qty_mt' => $qty, 'value_usd' => $value,
                'cost_per_mt' => $qty == 0.0 ? null : $value / $qty, 'source_cells' => $item['sourceCells'] ?? null,
                'source_note' => $d['openingSource'] ?? null,
            ]);
        }
    }
    private function seedConfig(string $file): void
    {
        foreach ($this->read($file) as $key => $value) SystemConfig::put($key, $value);
    }
    private function seedAccruals(string $file): void
    {
        foreach (($this->read($file)['entries'] ?? []) as $item) {
            Accrual::create($item);
        }
    }

    private function seedDbCostSummary(string $file): void
    {
        foreach ($this->read($file)['periods'] ?? [] as $period => $entries) {
            foreach ($entries as $item) {
                $item['period'] = $period;
            DbCostSummary::updateOrCreate(
                ['period' => $item['period'], 'entity' => $item['code'], 'activity' => $item['category']],
                ['amount' => $item['amount'] ?? 0, 'currency' => 'USD']
            );
            }
        }
    }
}
