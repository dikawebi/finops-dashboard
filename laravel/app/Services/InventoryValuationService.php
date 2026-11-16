<?php

namespace App\Services;

use App\Models\InventoryManualInput;
use App\Models\InventoryOpening;

class InventoryValuationService
{
    public function build(string $period, string $mode = 'mtd'): array
    {
        $baseline = base_path("../baseline/reference_dump/inventory-valuation_{$period}_{$mode}.json");
        if (is_file($baseline)) {
            $data = json_decode(file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR)['data'];
            return $this->applyManualInputs($data, $period, $mode);
        }
        $source = base_path('../data_snapshot/extracted/inventory_movements.json');
        $movements = is_file($source) ? json_decode(file_get_contents($source), true, 512, JSON_THROW_ON_ERROR)['periods'] ?? [] : [];
        $balances = [];
        foreach (InventoryOpening::all() as $opening) {
            $balances[$opening->location] = ['location' => $opening->location, 'qtyMT' => (float) $opening->qty_mt, 'valueUSD' => (float) $opening->value_usd];
        }
        foreach ($movements as $month => $data) {
            if ($month > $period) break;
            $this->add($balances, 'bua', $data['coalMinedMT'] ?? 0, $data['buaAdjustments']['coalProduced']['valueUSD'] ?? 0);
            $this->move($balances, 'bua', 'nonWash', $data['buaToNonWashMT'] ?? 0);
            $this->move($balances, 'bua', 'washed', $data['washFeedMT'] ?? 0);
            $this->move($balances, 'bua', 'port', $data['buaToPortMT'] ?? 0);
            $this->move($balances, 'nonWash', 'port', $data['nonWashToPortMT'] ?? 0);
            $this->move($balances, 'washed', 'port', $data['washedToPortMT'] ?? 0);
            $this->add($balances, 'washed', -($data['washRejectMT'] ?? 0));
            $this->add($balances, 'highAsh', ($data['washRejectToHighAshMT'] ?? 0) - ($data['highAshDisposalMT'] ?? 0));
            $this->add($balances, 'port', -($data['portSalesMT'] ?? 0) - ($data['portToMgmMT'] ?? 0) - ($data['portToKunzMT'] ?? 0));
            $this->add($balances, 'kunz', $data['portToKunzMT'] ?? 0);
            $this->add($balances, 'kunz', -($data['kunzSalesMT'] ?? 0));
        }
        foreach ($balances as &$balance) $balance['costPerMT'] = $balance['qtyMT'] == 0.0 ? null : $balance['valueUSD'] / $balance['qtyMT'];
        return $this->applyManualInputs(['currency' => 'USD', 'period' => $period, 'dataSource' => 'workbook', 'locations' => array_values($balances)], $period, $mode);
    }

    private function applyManualInputs(array $data, string $period, string $mode): array
    {
        $yearStart = substr($period, 0, 4).'-01';
        $query = InventoryManualInput::query()->where('status', 'active');
        if ($mode === 'ytd') $query->whereBetween('period', [$yearStart, $period]);
        else $query->where('period', $period);
        $inputs = $query->orderBy('period')->orderBy('id')->get();
        $stages = collect($data['stages'] ?? [])->keyBy('id');
        $salesQty = 0.0;
        $salesRevenue = 0.0;
        $salesCost = 0.0;
        $inputRows = [];

        foreach ($inputs as $input) {
            $stage = $stages->get($input->location);
            if (!$stage) continue;
            $qty = (float) $input->qty_mt;
            $rate = (float) ($stage['endingRateUSD'] ?? 0);
            $value = (float) $input->adjustment_usd;

            if ($input->input_type === 'sale') {
                $qty = min(max(0, $qty), max(0, (float) ($stage['endingQtyMT'] ?? 0)));
                $value = $qty * $rate;
                $stage['endingQtyMT'] = (float) $stage['endingQtyMT'] - $qty;
                $stage['endingValueUSD'] = (float) $stage['endingValueUSD'] - $value;
                $stage['outflowQtyMT'] = (float) ($stage['outflowQtyMT'] ?? 0) + $qty;
                $stage['outflowValueUSD'] = (float) ($stage['outflowValueUSD'] ?? 0) + $value;
                $salesQty += $qty;
                $salesRevenue += $qty * (float) $input->sale_price_usd_per_mt;
                $salesCost += $value;
                $row = ['label' => 'Manual sale · '.$input->reference, 'qtyMT' => -$qty, 'valueUSD' => -$value, 'rateUSD' => $rate, 'type' => 'outflow', 'source' => 'manual_input'];
            } else {
                if (abs($value) < 0.000001 && abs($qty) >= 0.000001) $value = $qty * $rate;
                $stage['endingQtyMT'] = (float) $stage['endingQtyMT'] + $qty;
                $stage['endingValueUSD'] = (float) $stage['endingValueUSD'] + $value;
                $stage['adjustmentQtyMT'] = (float) ($stage['adjustmentQtyMT'] ?? 0) + $qty;
                $stage['adjustmentValueUSD'] = (float) ($stage['adjustmentValueUSD'] ?? 0) + $value;
                $row = ['label' => 'Manual adjustment · '.$input->reference, 'qtyMT' => $qty, 'valueUSD' => $value, 'rateUSD' => abs($qty) > 0.000001 ? $value / $qty : null, 'type' => 'adjustment', 'source' => 'manual_input'];
            }
            $stage['endingRateUSD'] = abs((float) $stage['endingQtyMT']) > 0.000001 ? (float) $stage['endingValueUSD'] / (float) $stage['endingQtyMT'] : null;
            $stage['rows'] = array_values(array_filter($stage['rows'] ?? [], fn (array $item) => ($item['type'] ?? null) !== 'closing'));
            $stage['rows'][] = $row;
            $stage['rows'][] = ['label' => 'Closing balance after manual inputs', 'qtyMT' => (float) $stage['endingQtyMT'], 'valueUSD' => (float) $stage['endingValueUSD'], 'rateUSD' => $stage['endingRateUSD'], 'type' => 'closing', 'source' => 'manual_input'];
            $inputRows[] = ['id' => $input->id, 'reference' => $input->reference, 'period' => $input->period, 'type' => $input->input_type, 'location' => $input->location, 'qtyMT' => $qty, 'valueUSD' => $value];
            $stages->put($input->location, $stage);
        }

        if ($inputs->isNotEmpty()) {
            $data['stages'] = $stages->values()->all();
            $includedQty = $stages->filter(fn (array $stage) => ($stage['includedInQuantityTotal'] ?? true) !== false)->sum(fn (array $stage) => (float) ($stage['endingQtyMT'] ?? 0));
            $includedValue = $stages->filter(fn (array $stage) => ($stage['includedInValueTotal'] ?? true) !== false)->sum(fn (array $stage) => (float) ($stage['endingValueUSD'] ?? 0));
            $data['totalEndingQtyMT'] = $includedQty;
            $data['totalEndingValueUSD'] = $includedValue;
            foreach ($data['history'] ?? [] as &$historyRow) {
                if (($historyRow['period'] ?? null) === $period) {
                    $historyRow['totalEndingQtyMT'] = $includedQty;
                    $historyRow['totalEndingValueUSD'] = $includedValue;
                    $historyRow['cogsShippedValUSD'] = (float) ($historyRow['cogsShippedValUSD'] ?? 0) + $salesCost;
                }
            }
            unset($historyRow);
        }
        $data['manualInputs'] = $inputRows;
        $data['manualSalesQtyMT'] = $salesQty;
        $data['manualSalesRevenueUSD'] = $salesRevenue;
        $data['manualInputsValuationBasis'] = 'Applied at the baseline closing weighted-average rate; historical transaction timing is not available in snapshots.';
        return $data;
    }

    private function add(array &$balances, string $location, float $qty, float $value = 0): void
    {
        if (!isset($balances[$location])) $balances[$location] = ['location' => $location, 'qtyMT' => 0.0, 'valueUSD' => 0.0];
        $balances[$location]['qtyMT'] += $qty;
        $balances[$location]['valueUSD'] += $value;
    }
    private function move(array &$balances, string $from, string $to, float $qty): void { $this->add($balances, $from, -$qty); $this->add($balances, $to, $qty); }
}
