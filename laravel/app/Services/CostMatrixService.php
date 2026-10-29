<?php

namespace App\Services;

use App\Models\CostAccount;
use App\Models\GlEntry;
use Illuminate\Support\Collection;

/** Implements the Report Cost allocation rules from FORMULA_DASHBOARD.md §2. */
class CostMatrixService
{
    private const FIELDS = ['allCost','cek','valMining','valHauling','valWP','valPort','valNotValuated','totalValuation','costMining','costHauling','costHaulingBUAToWP','costHaulingBUAToPort','costHaulingWPToPort','unallocatedHauling','costWP','costPort','costGA','costSelling','totalCosting','totalCOGP','directGA','sellingExpense','totalExpense'];

    public function build(string $period, string $mode = 'mtd'): array
    {
        $rows = $this->emptyRows();
        $periods = $this->periods($period, $mode);
        $entries = GlEntry::whereIn('period', $periods)->get();
        foreach ($entries as $entry) {
            $code = (string) $entry->main_account;
            if (!isset($rows[$code])) continue;
            $this->allocate($rows[$code], $entry, (string) ($entry->amount_reporting ?? '0'));
        }
        foreach ($rows as &$row) $this->finish($row);
        unset($row);
        // Parent accounts are derived from their leaf children, never added twice.
        foreach ($rows as $code => &$row) {
            if (!$row['_parent']) continue;
            $children = array_filter($rows, fn ($child) => $child['_parent'] === $code);
            foreach (self::FIELDS as $field) { $row[$field] = $this->sum(array_column($children, $field)); }
        }
        unset($row);
        $public = array_map(fn ($row) => array_diff_key($row, ['_parent' => true]), array_values($rows));
        $grand = array_fill_keys(self::FIELDS, '0');
        foreach ($public as $row) {
            if ($row['isParent']) continue;
            foreach (self::FIELDS as $field) $grand[$field] = bcadd($grand[$field], (string) $row[$field], 6);
        }
        $historical = array_values(array_filter($periods, fn (string $value): bool => $value < '2026-10'));
        $accrual = array_values(array_filter($periods, fn (string $value): bool => $value >= '2026-10'));
        return [
            'currency' => 'USD', 'amountScale' => 1, 'mode' => $mode, 'period' => $period,
            'dataSource' => 'gl', 'capitalizationPeriods' => [],
            'adjustmentPeriods' => [], 'accrualPeriods' => $accrual,
            'historicalPeriods' => $historical, 'rows' => $public, 'grandTotal' => $grand,
        ];
    }

    private function emptyRows(): array
    {
        $result = [];
        foreach (CostAccount::orderBy('account_code')->get() as $account) {
            $row = ['code' => $account->account_code, 'name' => $account->account_name, 'isParent' => (bool) $account->is_parent, '_parent' => $account->parent_code];
            foreach (self::FIELDS as $field) $row[$field] = '0';
            $result[$account->account_code] = $row;
        }
        return $result;
    }

    private function allocate(array &$r, GlEntry $e, string $amount): void
    {
        $head = substr((string) $e->main_account, 0, 1);
        $valuation = strtolower(trim((string) $e->valuation_to));
        $costing = strtolower(trim((string) $e->costing_to));
        $r['allCost'] = bcadd($r['allCost'], $amount, 6);
        if (in_array($head, ['7','8','9'], true)) { $r['costGA'] = bcadd($r['costGA'], $amount, 6); return; }
        if (in_array($valuation, ['mining','pit'], true)) $r['valMining'] = bcadd($r['valMining'], $amount, 6);
        elseif (str_contains($valuation, 'hauling')) $r['valHauling'] = bcadd($r['valHauling'], $amount, 6);
        elseif (in_array($valuation, ['washing plant','wash','wp'], true)) $r['valWP'] = bcadd($r['valWP'], $amount, 6);
        elseif (in_array($valuation, ['bp port','port','jetty'], true)) $r['valPort'] = bcadd($r['valPort'], $amount, 6);
        elseif ($valuation === 'not valuated' || $valuation === '') $r['valNotValuated'] = bcadd($r['valNotValuated'], $amount, 6);
        if (str_contains($costing, 'selling') || str_contains($costing, 'marketing')) $r['costSelling'] = bcadd($r['costSelling'], $amount, 6);
        elseif (str_contains($costing, 'coal hauling')) {
            $r['costHauling'] = bcadd($r['costHauling'], $amount, 6);
            $route = strtolower((string) ($e->description . ' ' . $e->cost_center . ' ' . $e->project_name));
            if (str_contains($route, 'bua') && str_contains($route, 'wp')) $r['costHaulingBUAToWP'] = bcadd($r['costHaulingBUAToWP'], $amount, 6);
            elseif (str_contains($route, 'bua') && str_contains($route, 'port')) $r['costHaulingBUAToPort'] = bcadd($r['costHaulingBUAToPort'], $amount, 6);
            elseif (str_contains($route, 'wp') && str_contains($route, 'port')) $r['costHaulingWPToPort'] = bcadd($r['costHaulingWPToPort'], $amount, 6);
        } elseif (in_array($costing, ['mining','pit'], true)) $r['costMining'] = bcadd($r['costMining'], $amount, 6);
        elseif (in_array($costing, ['wash','wp','washing plant'], true)) $r['costWP'] = bcadd($r['costWP'], $amount, 6);
        elseif (in_array($costing, ['port','jetty','bp port'], true)) $r['costPort'] = bcadd($r['costPort'], $amount, 6);
        elseif (in_array($costing, ['direct expense','ga','general admin','admin','overhead'], true) || $valuation === 'not valuated') $r['costGA'] = bcadd($r['costGA'], $amount, 6);
    }

    private function finish(array &$r): void
    {
        $r['totalValuation'] = $this->sum([$r['valMining'],$r['valHauling'],$r['valWP'],$r['valPort'],$r['valNotValuated']]);
        $r['unallocatedHauling'] = bcsub($r['costHauling'], $this->sum([$r['costHaulingBUAToWP'],$r['costHaulingBUAToPort'],$r['costHaulingWPToPort']]), 6);
        $r['totalCosting'] = $this->sum([$r['costMining'],$r['costHauling'],$r['costWP'],$r['costPort'],$r['costGA'],$r['costSelling']]);
        $r['totalCOGP'] = $this->sum([$r['costMining'],$r['costHauling'],$r['costWP'],$r['costPort']]);
        $r['directGA'] = $this->sum([$r['valNotValuated'],$r['costGA']]);
        $r['sellingExpense'] = $r['costSelling'];
        $r['totalExpense'] = $this->sum([$r['directGA'],$r['sellingExpense']]);
        $r['cek'] = '0';
    }
    private function sum(array $values): string { $total = '0'; foreach ($values as $value) $total = bcadd($total, (string) $value, 6); return $total; }
    private function periods(string $period, string $mode): array { if ($mode !== 'ytd') return [$period]; [$year] = explode('-', $period); return array_map(fn ($m) => sprintf('%s-%02d', $year, $m), range(1, (int) substr($period, 5, 2))); }
}
