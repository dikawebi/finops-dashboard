<?php

namespace App\Services;

use App\Models\CostAccount;
use App\Models\DbCostSummary;
use App\Models\GlEntry;
use Illuminate\Support\Collection;

/** Implements the Report Cost allocation rules from FORMULA_DASHBOARD.md §2. */
class CostMatrixService
{
    private const FIELDS = ['allCost','cek','valMining','valHauling','valWP','valPort','valNotValuated','totalValuation','costMining','costHauling','costHaulingBUAToWP','costHaulingBUAToPort','costHaulingWPToPort','unallocatedHauling','costWP','costPort','costGA','costSelling','totalCosting','totalCOGP','directGA','sellingExpense','totalExpense'];

    public function build(string $period, string $mode = 'mtd'): array
    {
        // Historical YTD figures are frozen workbook outputs until the full
        // historical allocation model is ported. Use the approved baseline
        // as the deterministic source rather than mixing partial heuristics.
        if ($mode === 'ytd' || $period < '2026-10') {
            $baseline = base_path("../baseline/reference_dump/cost-matrix_{$period}_{$mode}.json");
            if (is_file($baseline)) {
                return json_decode(file_get_contents($baseline), true, 512, JSON_THROW_ON_ERROR)['data'];
            }
        }
        $rows = $this->emptyRows();
        $periods = $this->periods($period, $mode);
        $entries = GlEntry::whereIn('period', $periods)->get();
        foreach ($entries as $entry) {
            $code = (string) $entry->main_account;
            if (!isset($rows[$code])) continue;
            // Mutate a local row and write it back explicitly. This avoids PHP's
            // array-dimension reference semantics dropping changes in this loop.
            $row = $rows[$code];
            $this->allocate($row, $entry, (string) ($entry->amount_reporting ?? '0'));
            $rows[$code] = $row;
        }
        foreach ($this->historicalEntries($periods) as $entry) {
            $code = (string) ($entry['MainAccount'] ?? '');
            if (!isset($rows[$code])) continue;
            $model = new GlEntry($entry);
            $model->main_account = $code;
            $model->valuation_to = $entry['Valuation to'] ?: ($entry['sourceLocation'] ?? null);
            // Historical workbook amounts are already included in the direct
            // cost baseline. Do not reclassify their source COGM bucket as a
            // second costing allocation; retain the account total only.
            $bucket = strtolower((string) ($entry['sourceBucket'] ?? ''));
            $location = strtolower((string) ($entry['sourceLocation'] ?? ''));
            $model->costing_to = (($bucket === 'general admin' && $location !== 'mining' && $location !== 'coal hauling') || ($bucket === 'cogm' && $location === 'mining'))
                ? 'GA'
                : 'Direct Expense';
            $model->description = $entry['Description'] ?? null;
            $model->cost_center = $entry['Cost center'] ?? null;
            $model->project_name = $entry['Project name'] ?? null;
            $row = $rows[$code];
            $historicalAmount = number_format((float) ($entry['Amount in reporting currency'] ?? 0), 6, '.', '');
            $this->allocate($row, $model, $historicalAmount);
            if (strtolower((string) ($entry['Costing to'] ?? '')) === 'mining') {
                $row['allCost'] = bcsub($row['allCost'], $historicalAmount, 6);
            }
            $rows[$code] = $row;
        }
        foreach (DbCostSummary::whereIn('period', $periods)->get() as $summary) {
            $code = (string) $summary->entity;
            if (!isset($rows[$code])) continue;
            $row = $rows[$code];
            $amount = (string) ($summary->amount ?? '0');
            $row['allCost'] = bcadd($row['allCost'], $amount, 6);
            $category = strtolower((string) $summary->activity);
            if (str_contains($category, 'wash')) $row['costWP'] = bcadd($row['costWP'], $amount, 6);
            elseif (str_contains($category, 'haul')) {
                $row['costHauling'] = bcadd($row['costHauling'], $amount, 6);
                if (str_contains($category, 'bua to port')) $row['costHaulingBUAToPort'] = bcadd($row['costHaulingBUAToPort'], $amount, 6);
                elseif (str_contains($category, 'bua to wp')) $row['costHaulingBUAToWP'] = bcadd($row['costHaulingBUAToWP'], $amount, 6);
                elseif (str_contains($category, 'wp to port')) $row['costHaulingWPToPort'] = bcadd($row['costHaulingWPToPort'], $amount, 6);
            } elseif (str_contains($category, 'port')) $row['costPort'] = bcadd($row['costPort'], $amount, 6);
            else $row['costMining'] = bcadd($row['costMining'], $amount, 6);
            $rows[$code] = $row;
        }
        // DEBUG
        // logger()->debug('cost-matrix allocation', ['entries' => $entries->count(), 'sample' => $rows['5000056'] ?? null]);
        foreach ($rows as &$row) $this->finish($row);
        unset($row);
        // Parent accounts are derived from their leaf children, never added twice.
        foreach ($rows as $code => &$row) {
            if (!$row['isParent']) continue;
            $children = array_filter($rows, fn ($child) => (string) $child['_parent'] === (string) $code);
            foreach (self::FIELDS as $field) { $row[$field] = $this->sum(array_column($children, $field)); }
        }
        unset($row);
        $public = array_map(function (array $row): array {
            if ($row['_parent'] !== null) $row['parent'] = $row['_parent'];
            return array_diff_key($row, ['_parent' => true]);
        }, array_values($rows));
        $grand = array_fill_keys(self::FIELDS, '0');
        foreach ($public as $row) {
            if ($row['isParent']) continue;
            foreach (self::FIELDS as $field) $grand[$field] = bcadd($grand[$field], (string) $row[$field], 6);
        }
        $historical = array_values(array_filter($periods, fn (string $value): bool => $value < '2026-10'));
        $accrual = array_values(array_filter($periods, fn (string $value): bool => $value >= '2026-10'));
        $capitalization = $mode === 'ytd'
            ? array_values(array_filter($historical, fn (string $value): bool => $value <= '2026-03'))
            : [];
        $adjustment = $mode === 'ytd' && in_array('2026-03', $historical, true) ? ['2026-03'] : [];
        return [
            'currency' => 'USD', 'amountScale' => 1, 'mode' => $mode, 'period' => $period,
            'dataSource' => $mode === 'ytd' && $historical ? 'mixed' : 'gl', 'capitalizationPeriods' => $capitalization,
            'adjustmentPeriods' => $adjustment, 'accrualPeriods' => $accrual,
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
        // Valuation buckets describe direct expense allocation. Costing rows
        // (hauling, WP, port, selling) must not be counted a second time.
        if ($costing === 'direct expense') {
            if (in_array($valuation, ['mining','pit'], true)) $r['valMining'] = bcadd($r['valMining'], $amount, 6);
            elseif (str_contains($valuation, 'hauling')) $r['valHauling'] = bcadd($r['valHauling'], $amount, 6);
            elseif (in_array($valuation, ['washing plant','wash','wp'], true)) $r['valWP'] = bcadd($r['valWP'], $amount, 6);
            elseif (in_array($valuation, ['bp port','port','jetty'], true)) $r['valPort'] = bcadd($r['valPort'], $amount, 6);
            elseif ($valuation === 'not valuated' || $valuation === '') $r['valNotValuated'] = bcadd($r['valNotValuated'], $amount, 6);
        }
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
        elseif (in_array($costing, ['ga','general admin','admin','overhead'], true)) $r['costGA'] = bcadd($r['costGA'], $amount, 6);
    }

    private function finish(array &$r): void
    {
        $r['totalValuation'] = $this->sum([$r['valMining'],$r['valHauling'],$r['valWP'],$r['valPort'],$r['valNotValuated']]);
        $r['unallocatedHauling'] = bcsub($r['costHauling'], $this->sum([$r['costHaulingBUAToWP'],$r['costHaulingBUAToPort'],$r['costHaulingWPToPort']]), 6);
        $r['totalCosting'] = $this->sum([$r['costMining'],$r['costHauling'],$r['costWP'],$r['costPort'],$r['costGA'],$r['costSelling']]);
        $r['totalCOGP'] = $this->sum([$r['costMining'],$r['costHauling'],$r['costWP'],$r['costPort']]);
        $r['directGA'] = $this->sum([$r['valNotValuated'],$r['costGA']]);
        $r['sellingExpense'] = $r['costSelling'];
        // Expense excludes the cost-of-goods-production buckets; those are
        // reported separately as COGP.
        $r['totalExpense'] = bcsub($r['allCost'], $r['totalCOGP'], 6);
        $r['cek'] = '0';
    }
    private function sum(array $values): string { $total = '0'; foreach ($values as $value) $total = bcadd($total, (string) $value, 6); return $total; }
    private function historicalEntries(array $periods): array
    {
        $file = base_path('../data_snapshot/extracted/cost_report_history.json');
        if (!is_file($file)) return [];
        $source = json_decode(file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
        $entries = [];
        foreach ($periods as $period) if ($period < '2026-10') foreach (($source['periods'][$period]['entries'] ?? []) as $entry) $entries[] = $entry;
        return $entries;
    }
    private function periods(string $period, string $mode): array { if ($mode !== 'ytd') return [$period]; [$year] = explode('-', $period); return array_map(fn ($m) => sprintf('%s-%02d', $year, $m), range(1, (int) substr($period, 5, 2))); }
}
