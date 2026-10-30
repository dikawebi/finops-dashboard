<?php

namespace App\Services;

use App\Models\FinancialBaseline;
use App\Services\CostMatrixService;

class FinancialStatementService
{
    public function build(string $period, string $mode = 'mtd', ?CostMatrixService $costs = null): array
    {
        $baselinePath = base_path("../baseline/reference_dump/financial-statements_{$period}_{$mode}.json");
        if (is_file($baselinePath)) {
            $payload = json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR);
            $data = $payload['data'] ?? [];
            $report = $data['report'] ?? [];
            $sections = [];

            foreach (['profitLoss', 'balanceSheet'] as $section) {
                $sections[$section] = array_map(
                    static function (array $line) use ($period, $mode): array {
                        $value = $mode === 'ytd'
                            ? ($line['ytd'] ?? null)
                            : (($line['months'][$period] ?? null));
                        $line['value'] = $value;
                        return $line;
                    },
                    $report[$section] ?? []
                );
            }

            return [
                'currency' => $data['currency'] ?? 'USD',
                'amountScale' => $data['amountScale'] ?? 1,
                'period' => $period,
                'mode' => $mode,
                'dataSource' => 'financial-baseline',
                'sections' => $sections,
                'report' => $report,
                'cashFlowEnabled' => (bool) ($report['cashFlowEnabled'] ?? false),
                'incomeStatement' => $data['incomeStatement'] ?? [],
                'balanceSheet' => $data['balanceSheet'] ?? [],
                'accountBalances' => $data['accountBalances'] ?? [],
                'diagnostics' => $data['diagnostics'] ?? [],
                'inventoryReconciliation' => $data['inventoryReconciliation'] ?? [
                    'status' => 'provisional',
                ],
                'setupRequired' => (bool) ($data['setupRequired'] ?? false),
                'inventoryProvisional' => (bool) ($data['inventoryProvisional'] ?? false),
            ];
        }

        $baseline = FinancialBaseline::first();
        if (!$baseline) return ['period' => $period, 'mode' => $mode, 'sections' => []];
        $month = $period;
        $sections = [];
        foreach (($baseline->sections ?? []) as $section => $lines) {
            $sections[$section] = array_map(function (array $line) use ($month, $mode): array {
                $value = $mode === 'ytd'
                    ? ($line['baselineYTD'] ?? 0)
                    : (($line['months'][$month] ?? 0));
                $line['value'] = number_format((float) $value, 6, '.', '');
                return $line;
            }, $lines);
        }
        $matrix = $costs?->build($period, $mode);
        $totalOpex = (float) ($matrix['grandTotal']['allCost'] ?? 0);
        $selling = (float) ($matrix['grandTotal']['sellingExpense'] ?? 0);
        $income = ['period' => $period, 'currency' => $baseline->currency, 'salesVolumeMT' => null, 'revenue' => ['totalRevenue' => 0], 'cogs' => ['totalCOGS' => 0], 'grossProfit' => 0, 'grossProfitMarginPct' => 0, 'operatingExpenses' => ['generalAdministrative' => $totalOpex - $selling, 'sellingExpense' => $selling, 'royaltyCoalSales' => 0, 'totalOpex' => $totalOpex], 'operatingProfitEBITDA' => -$totalOpex, 'ebitdaMarginPct' => 0, 'otherItems' => ['financeIncome' => 0, 'financeCost' => 0, 'forexGainLoss' => 0, 'includedInGA' => false], 'netProfitBeforeTax' => -$totalOpex, 'netProfitAfterTax' => -$totalOpex, 'netProfitMarginPct' => 0];
        $balanceRows = $sections['balanceSheet'] ?? [];
        return ['currency' => $baseline->currency, 'period' => $period, 'mode' => $mode, 'dataSource' => 'financial-baseline', 'sections' => $sections, 'cashFlowEnabled' => (bool) $baseline->cash_flow_enabled, 'incomeStatement' => $income, 'report' => $sections, 'accountBalances' => $balanceRows, 'diagnostics' => ['cashFlowEnabled' => (bool) $baseline->cash_flow_enabled, 'source' => 'financial_baseline.json'], 'inventoryReconciliation' => ['bookInventoryUSD' => null, 'coalInventoryUSD' => null, 'status' => 'provisional']];
    }
}
