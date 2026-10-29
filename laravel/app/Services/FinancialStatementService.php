<?php

namespace App\Services;

use App\Models\FinancialBaseline;

class FinancialStatementService
{
    public function build(string $period, string $mode = 'mtd'): array
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
        return ['currency' => $baseline->currency, 'period' => $period, 'mode' => $mode, 'dataSource' => 'financial-baseline', 'sections' => $sections, 'cashFlowEnabled' => (bool) $baseline->cash_flow_enabled];
    }
}
