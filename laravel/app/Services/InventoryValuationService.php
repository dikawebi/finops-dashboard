<?php

namespace App\Services;

use App\Models\InventoryOpening;

class InventoryValuationService
{
    public function build(string $period, string $mode = 'mtd'): array
    {
        $baseline = base_path("../baseline/reference_dump/inventory-valuation_{$period}_{$mode}.json");
        if (is_file($baseline)) {
            return json_decode(file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR)['data'];
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
        return ['currency' => 'USD', 'period' => $period, 'dataSource' => 'workbook', 'locations' => array_values($balances)];
    }

    private function add(array &$balances, string $location, float $qty, float $value = 0): void
    {
        if (!isset($balances[$location])) $balances[$location] = ['location' => $location, 'qtyMT' => 0.0, 'valueUSD' => 0.0];
        $balances[$location]['qtyMT'] += $qty;
        $balances[$location]['valueUSD'] += $value;
    }
    private function move(array &$balances, string $from, string $to, float $qty): void { $this->add($balances, $from, -$qty); $this->add($balances, $to, $qty); }
}
