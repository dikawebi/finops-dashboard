<?php

namespace App\Services;

use App\Models\FinancialBaseline;
use App\Services\CostMatrixService;

class FinancialStatementService
{
    public function build(string $period, string $mode = 'mtd', ?CostMatrixService $costs = null): array
    {
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
        return ['currency' => $baseline->currency, 'period' => $period, 'mode' => $mode, 'dataSource' => 'financial-baseline', 'sections' => $sections, 'cashFlowEnabled' => (bool) $baseline->cash_flow_enabled, 'incomeStatement' => $income, 'report' => $sections];
    }
}
