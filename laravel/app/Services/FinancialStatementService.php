<?php

namespace App\Services;

use App\Models\FinancialAccountMapping;
use App\Models\FinancialBaseline;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Laravel port of the reference financial_engine.js. */
class FinancialStatementService
{
    private const BS_SUMS = [
        'cashAndEquivalents'=>['cashOnHand','cashInBanks','timeDeposits'],
        'prepaidTaxesAdvances'=>['advances','prepayments','prepaidTaxes'],
        'totalCurrentAssets'=>['cashAndEquivalents','tradeReceivables','prepaidTaxesAdvances','inventories','derivativeAsset'],
        'totalNonCurrentAssets'=>['restrictedCash','miningProperties','explorationAssets','fixedAssets','retirementAsset','deferredTaxAsset'],
        'totalAssets'=>['totalCurrentAssets','totalNonCurrentAssets'],
        'totalCurrentLiabilities'=>['loansCurrent','financeLeaseCurrent','tradePayables','accruedExpenses','taxesPayable','advancesCustomer','otherCurrentLiabilities','derivativeLiability'],
        'totalNonCurrentLiabilities'=>['loansNonCurrent','financeLeaseNonCurrent','employeeBenefits','reclamationProvision','deferredTaxLiabilities'],
        'totalLiabilities'=>['totalCurrentLiabilities','totalNonCurrentLiabilities'],
        'totalLiabilitiesEquity'=>['totalLiabilities','totalEquity'],
    ];

    private const PL_SUMS = [
        'grossProfit'=>['coalSales','costOfCoalSold'],
        'profitBeforeInterest'=>['grossProfit','sellingExpense','royaltyCoalSales','generalAdmin'],
        'profitBeforeTax'=>['profitBeforeInterest','financeIncome','financeCost','realisedFX','unrealisedFX'],
        'profitForYear'=>['profitBeforeTax','incomeTax'],
    ];

    private const CREDIT_ROWS = [
        'loansCurrent','financeLeaseCurrent','tradePayables','accruedExpenses','taxesPayable','advancesCustomer',
        'otherCurrentLiabilities','derivativeLiability','loansNonCurrent','financeLeaseNonCurrent','employeeBenefits',
        'reclamationProvision','deferredTaxLiabilities','totalEquity',
    ];

    public function build(string $period, string $mode = 'mtd', ?CostMatrixService $costs = null): array
    {
        unset($costs);
        $mode = strtolower($mode) === 'ytd' ? 'ytd' : 'mtd';
        $baselineModel = FinancialBaseline::first();
        if (!$baselineModel) return ['period'=>$period,'mode'=>$mode,'sections'=>[]];

        $baseline = [
            'company'=>$baselineModel->company,
            'currency'=>$baselineModel->currency,
            'basePeriod'=>$baselineModel->base_period,
            'glStartPeriod'=>$baselineModel->gl_start_period,
            'year'=>(int) $baselineModel->year,
            'comparativeYear'=>(int) ($baselineModel->comparative_year ?? $baselineModel->year - 1),
            'sections'=>$baselineModel->sections ?? [],
            'accounts'=>$baselineModel->accounts ?? [],
        ];
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) || $period < sprintf('%04d-01', $baseline['year'])) {
            throw new InvalidArgumentException("Financial Statement is available from {$baseline['year']}-01.");
        }

        $mappings = FinancialAccountMapping::orderBy('account_code')->get()->keyBy('account_code')
            ->map(static fn (FinancialAccountMapping $item): array => [
                'name'=>$item->account_name,
                'rowId'=>$item->row_id,
                'sourceLabel'=>$item->source_label,
                'referenceSep'=>$item->reference_sep === null ? null : (float) $item->reference_sep,
                'referenceOct'=>$item->reference_oct === null ? null : (float) $item->reference_oct,
            ])->all();

        $futurePeriods = $period >= $baseline['glStartPeriod']
            ? $this->periodsBetween($baseline['glStartPeriod'], $period)
            : [];
        $byMonth = array_fill_keys($futurePeriods, ['pl'=>[],'bs'=>[],'accounts'=>[],'rows'=>0,'adjustmentRows'=>0,'ledgerSum'=>0.0]);
        $accounts = [];
        foreach ($baseline['accounts'] as $account) $accounts[(string) $account['code']] = $account;

        // Reclassify the September YTD baseline per the account-level reference.
        // Historical monthly columns intentionally keep their original classification.
        $profitLoss = $baseline['sections']['profitLoss'] ?? [];
        $plBase = [];
        foreach ($profitLoss as $line) if (($line['kind'] ?? '') !== 'heading') $plBase[$line['id']] = (float) ($line['baselineYTD'] ?? 0);
        $mappedBase = [];
        foreach ($mappings as $mapping) {
            $sourceId = $this->lineIdForLabel($profitLoss, (string) ($mapping['sourceLabel'] ?? ''));
            if ($sourceId !== null) $mappedBase[$sourceId] = 0.0;
        }
        foreach ($mappings as $code => $mapping) {
            $rowId = (string) ($mapping['rowId'] ?? '');
            if (preg_match('/^[3-9]/', (string) $code) && array_key_exists($rowId, $plBase) && $mapping['referenceSep'] !== null) {
                $mappedBase[$rowId] = ($mappedBase[$rowId] ?? 0.0) + (float) $mapping['referenceSep'];
            }
        }
        $plBaseAdjust = [];
        foreach ($mappedBase as $id => $value) $plBaseAdjust[$id] = $value - ($plBase[$id] ?? 0.0);
        $this->applySums($plBaseAdjust, self::PL_SUMS);
        foreach ($plBaseAdjust as $id => $value) $plBase[$id] = ($plBase[$id] ?? 0.0) + $value;

        // Reclassify baseline balance-sheet accounts with the same debit/credit
        // presentation sign rules used for subsequent GL movements.
        $balanceSheet = $baseline['sections']['balanceSheet'] ?? [];
        $bsBaseAdjust = [];
        foreach ($mappings as $code => $mapping) {
            $account = $accounts[(string) $code] ?? null;
            $target = (string) ($mapping['rowId'] ?? '');
            $original = (string) ($account['bsRowId'] ?? '');
            if (!$account || $original === '' || $target === '' || $original === $target || !$this->isBalanceSheetTarget($balanceSheet, $target)) continue;
            $value = (float) ($account['baselineClosingUSD'] ?? 0);
            $this->add($bsBaseAdjust, $original, -$value * ($this->isCreditRow($original) ? -1 : 1));
            $this->add($bsBaseAdjust, $target, $value * ($this->isCreditRow($target) ? -1 : 1));
        }
        $this->applySums($bsBaseAdjust, self::BS_SUMS);

        $glRows = DB::table('gl_entries')->where('source_type', 'd365')
            ->whereBetween('period', [$baseline['glStartPeriod'], $period])
            ->select(['period','main_account','account_name','costing_to','amount_reporting'])
            ->orderBy('period')->get();
        $movementByAccount = [];
        foreach ($glRows as $entry) {
            $month = (string) $entry->period;
            if (!isset($byMonth[$month])) continue;
            $code = trim((string) $entry->main_account);
            $amount = (float) ($entry->amount_reporting ?? 0);
            $byMonth[$month]['rows']++;
            $byMonth[$month]['ledgerSum'] += $amount;
            $byMonth[$month]['accounts'][$code] = ($byMonth[$month]['accounts'][$code] ?? 0.0) + $amount;
            $movementByAccount[$code] = ($movementByAccount[$code] ?? 0.0) + $amount;

            $mapping = $mappings[$code] ?? null;
            $account = $accounts[$code] ?? null;
            $bsId = $this->mappedBalanceSheetId($code, $mapping, $account, $balanceSheet);
            if ($bsId !== null) {
                $this->add($byMonth[$month]['bs'], $bsId, $amount * ($this->isCreditRow($bsId) ? -1 : 1));
            } elseif (abs($amount) > 0.000001) {
                $this->addUnmapped($unmappedAccounts ??= [], $code, $month, (string) ($entry->account_name ?? ''), $amount, 'Balance Sheet');
            }

            $plId = $this->mappedProfitLossId($mapping, $profitLoss)
                ?? $this->profitLossLine($code, (string) ($entry->costing_to ?? ''));
            if ($plId !== null) {
                $this->add($byMonth[$month]['pl'], $plId, -$amount);
            } elseif (preg_match('/^[3-9]/', $code) && abs($amount) > 0.000001) {
                $this->addUnmapped($unmappedAccounts ??= [], $code, $month, (string) ($entry->account_name ?? ''), $amount, 'P&L');
            }
        }
        $unmappedAccounts ??= [];
        foreach ($byMonth as &$monthData) {
            $this->applySums($monthData['pl'], self::PL_SUMS);
            $this->applySums($monthData['bs'], self::BS_SUMS);
        }
        unset($monthData);

        $plValue = function (array $line, string $to) use ($baseline, $plBase, $byMonth): float {
            $targetYear = (int) substr($to, 0, 4);
            if ($targetYear === $baseline['comparativeYear']) return (float) ($line['comparative'] ?? 0);
            if ($targetYear === $baseline['year'] && $to >= $baseline['basePeriod']) {
                return (float) ($plBase[$line['id']] ?? $line['baselineYTD'] ?? 0)
                    + $this->delta($byMonth, 'pl', $line['id'], $baseline['glStartPeriod'], $to);
            }
            $total = 0.0;
            foreach (($line['months'] ?? []) as $month => $value) if ($month <= $to) $total += (float) $value;
            return $total;
        };
        $bsValue = function (array $line, string $to) use ($baseline, $bsBaseAdjust, $byMonth): float {
            if ((int) substr($to, 0, 4) === $baseline['comparativeYear']) return (float) ($line['comparative'] ?? 0);
            if ($to <= $baseline['basePeriod']) return (float) ($line['months'][$to] ?? 0);
            return (float) ($line['baselineYTD'] ?? 0) + (float) ($bsBaseAdjust[$line['id']] ?? 0)
                + $this->delta($byMonth, 'bs', $line['id'], $baseline['glStartPeriod'], $to);
        };

        $yearMonths = $this->periodsBetween(sprintf('%04d-01', (int) substr($period, 0, 4)), $period);
        $comparativePeriod = sprintf('%04d-12', $baseline['comparativeYear']);
        $reportPl = array_map(function (array $line) use ($period, $mode, $yearMonths, $baseline, $plValue, $byMonth): array {
            $months = [];
            foreach ($yearMonths as $month) {
                if (($line['kind'] ?? '') === 'heading') { $months[$month] = null; continue; }
                if ($month <= $baseline['basePeriod']) $months[$month] = $line['months'][$month] ?? null;
                elseif (($byMonth[$month]['rows'] ?? 0) > 0 || ($byMonth[$month]['adjustmentRows'] ?? 0) > 0) $months[$month] = (float) ($byMonth[$month]['pl'][$line['id']] ?? 0);
                else $months[$month] = null;
            }
            return [
                'id'=>$line['id'],'label'=>$line['label'],'kind'=>$line['kind'],
                'comparative'=>($line['kind'] ?? '') === 'heading' ? null : (float) ($line['comparative'] ?? 0),
                'ytd'=>($line['kind'] ?? '') === 'heading' ? null : $plValue($line, $period),
                'months'=>$months,
            ];
        }, $profitLoss);
        $reportBs = array_map(function (array $line) use ($period, $yearMonths, $baseline, $bsValue): array {
            $months = [];
            foreach ($yearMonths as $month) {
                $months[$month] = ($line['kind'] ?? '') === 'heading'
                    ? null
                    : ($month <= $baseline['basePeriod'] ? ($line['months'][$month] ?? null) : $bsValue($line, $month));
            }
            return [
                'id'=>$line['id'],'label'=>$line['label'],'kind'=>$line['kind'],
                'comparative'=>($line['kind'] ?? '') === 'heading' ? null : (float) ($line['comparative'] ?? 0),
                'ytd'=>($line['kind'] ?? '') === 'heading' ? null : $bsValue($line, $period),
                'months'=>$months,
            ];
        }, $balanceSheet);

        $report = [
            'company'=>$baseline['company'],'basePeriod'=>$baseline['basePeriod'],'glStartPeriod'=>$baseline['glStartPeriod'],
            'period'=>$period,'year'=>(int) substr($period, 0, 4),'comparativeYear'=>$baseline['comparativeYear'],
            'periods'=>$yearMonths,'profitLoss'=>$reportPl,'balanceSheet'=>$reportBs,
            'cashFlowEnabled'=>(bool) $baselineModel->cash_flow_enabled,
            'glMonths'=>array_map(static fn (array $data): array => [
                'rows'=>$data['rows'],'adjustmentRows'=>$data['adjustmentRows'],'ledgerSumUSD'=>$data['ledgerSum'],
            ], $byMonth),
        ];

        $pl = [];
        foreach ($reportPl as $line) $pl[$line['id']] = (float) ($mode === 'ytd' ? ($line['ytd'] ?? 0) : ($line['months'][$period] ?? 0));
        $bs = [];
        foreach ($reportBs as $line) $bs[$line['id']] = (float) ($line['ytd'] ?? 0);
        $baselineBs = [];
        foreach ($balanceSheet as $line) $baselineBs[$line['id']] = (float) ($line['baselineYTD'] ?? 0);
        $missingGLPeriods = array_values(array_filter($futurePeriods, static fn (string $month): bool => ($byMonth[$month]['rows'] ?? 0) === 0));
        $diagnostics = [
            'missingGLPeriods'=>$missingGLPeriods,
            'unmappedAccounts'=>array_values($unmappedAccounts),
            'invalidAmounts'=>[],
            'baselineBalanceDifferenceUSD'=>(float) ($baselineBs['totalAssets'] ?? 0) - (float) ($baselineBs['totalLiabilitiesEquity'] ?? 0),
            'baselineDifferenceUSD'=>(float) ($baselineBs['totalAssets'] ?? 0) - (float) ($baselineBs['totalLiabilitiesEquity'] ?? 0),
            'balanceDifferenceUSD'=>(float) ($bs['totalAssets'] ?? 0) - (float) ($bs['totalLiabilitiesEquity'] ?? 0),
            'glLedgerDifferenceUSD'=>array_sum(array_column($byMonth, 'ledgerSum')),
            'syncedGLRows'=>array_sum(array_column($byMonth, 'rows')),
            'source'=>'gl_entries (D365) + financial baseline + account-level financial mapping',
        ];

        $accountBalances = [];
        foreach ($baseline['accounts'] as $account) {
            $code = (string) $account['code'];
            $movement = (float) ($movementByAccount[$code] ?? 0);
            $accountBalances[] = [
                'code'=>$code,'name'=>$account['name'],'mapping'=>$account['sourceMapping'] ?? null,
                'baseUSD'=>(float) ($account['baselineClosingUSD'] ?? 0),'glMovementUSD'=>$movement,
                'closingUSD'=>(float) ($account['baselineClosingUSD'] ?? 0) + $movement,
            ];
        }

        $sections = [
            'profitLoss'=>$this->selectValues($reportPl, $period, $mode),
            'balanceSheet'=>$this->selectValues($reportBs, $period, 'ytd'),
        ];
        $revenue = $pl['coalSales'] ?? 0;
        $gross = $pl['grossProfit'] ?? 0;
        $beforeInterest = $pl['profitBeforeInterest'] ?? 0;
        $beforeTax = $pl['profitBeforeTax'] ?? 0;
        $payload = [
            'currency'=>$baseline['currency'],'amountScale'=>1,'setupRequired'=>false,'inventoryProvisional'=>false,
            'period'=>$period,'mode'=>$mode,'dataSource'=>'d365-gl+financial-baseline','report'=>$report,
            'diagnostics'=>$diagnostics,'accountBalances'=>$accountBalances,'sections'=>$sections,
            'balanceSheet'=>$sections['balanceSheet'],
            'incomeStatement'=>[
                'period'=>$period,'currency'=>$baseline['currency'],'salesVolumeMT'=>null,
                'revenue'=>['totalRevenue'=>$revenue],'cogs'=>['totalCOGS'=>-($pl['costOfCoalSold'] ?? 0)],
                'grossProfit'=>$gross,'grossProfitMarginPct'=>$revenue == 0 ? 0 : $gross / $revenue * 100,
                'operatingExpenses'=>[
                    'generalAdministrative'=>-($pl['generalAdmin'] ?? 0),'sellingExpense'=>-($pl['sellingExpense'] ?? 0),
                    'royaltyCoalSales'=>-($pl['royaltyCoalSales'] ?? 0),
                    'totalOpex'=>-($pl['generalAdmin'] ?? 0)-($pl['sellingExpense'] ?? 0)-($pl['royaltyCoalSales'] ?? 0),
                ],
                'operatingProfitEBITDA'=>$beforeInterest,'ebitdaMarginPct'=>$revenue == 0 ? 0 : $beforeInterest / $revenue * 100,
                'otherItems'=>[
                    'financeIncome'=>$pl['financeIncome'] ?? 0,'financeCost'=>-($pl['financeCost'] ?? 0),
                    'forexGainLoss'=>-($pl['realisedFX'] ?? 0)-($pl['unrealisedFX'] ?? 0),'includedInGA'=>false,
                ],
                'netProfitBeforeTax'=>$beforeTax,'netProfitAfterTax'=>$pl['profitForYear'] ?? $beforeTax,
                'netProfitMarginPct'=>$revenue == 0 ? 0 : $beforeTax / $revenue * 100,
            ],
        ];

        return $payload;
    }

    private function profitLossLine(string $code, string $costingTo): ?string
    {
        if (str_starts_with($code, '3')) return 'coalSales';
        if (str_starts_with($code, '41')) return 'costOfCoalSold';
        if (str_starts_with($code, '400')) return 'generalAdmin';
        if (str_starts_with($code, '5')) return preg_match('/sell|market|mkt/i', $costingTo) ? 'sellingExpense' : 'generalAdmin';
        if (in_array($code, ['6000003','6000004'], true)) return 'royaltyCoalSales';
        if (str_starts_with($code, '6')) return 'sellingExpense';
        if (preg_match('/^(700000[123]|700600[12]|7007001)$/', $code)) return 'financeIncome';
        if ($code === '7002002') return 'realisedFX';
        if ($code === '7002001') return 'unrealisedFX';
        if (preg_match('/^(7001|7003|7004|8)/', $code)) return 'financeCost';
        if (str_starts_with($code, '9')) return 'generalAdmin';
        return null;
    }

    private function mappedProfitLossId(?array $mapping, array $lines): ?string
    {
        if (!$mapping) return null;
        $id = (string) ($mapping['rowId'] ?? '');
        foreach ($lines as $line) if (($line['id'] ?? null) === $id && ($line['kind'] ?? null) === 'detail') return $id;
        return null;
    }

    private function mappedBalanceSheetId(string $code, ?array $mapping, ?array $account, array $lines): ?string
    {
        $mapped = (string) ($mapping['rowId'] ?? '');
        if ($mapped !== '' && $this->isBalanceSheetTarget($lines, $mapped)) return $mapped;
        $baseline = (string) ($account['bsRowId'] ?? '');
        if ($baseline !== '') return $baseline;
        return preg_match('/^[3-9]/', $code) ? 'totalEquity' : null;
    }

    private function isBalanceSheetTarget(array $lines, string $id): bool
    {
        foreach ($lines as $line) if (($line['id'] ?? null) === $id && (($line['kind'] ?? null) === 'detail' || $id === 'totalEquity')) return true;
        return false;
    }

    private function isCreditRow(string $id): bool { return in_array($id, self::CREDIT_ROWS, true); }

    private function lineIdForLabel(array $lines, string $label): ?string
    {
        $normalize = static fn (string $value): string => strtoupper((string) preg_replace('/\s+/', ' ', trim($value)));
        foreach ($lines as $line) if (($line['kind'] ?? '') === 'detail' && $normalize((string) ($line['label'] ?? '')) === $normalize($label)) return (string) $line['id'];
        return null;
    }

    private function add(array &$values, string $key, float $amount): void { $values[$key] = ($values[$key] ?? 0.0) + $amount; }

    private function addUnmapped(array &$items, string $code, string $month, string $name, float $amount, string $statement): void
    {
        $key = $statement.'|'.$month.'|'.$code;
        if (!isset($items[$key])) $items[$key] = ['code'=>$code,'month'=>$month,'name'=>$name,'amountUSD'=>0.0,'statement'=>$statement];
        $items[$key]['amountUSD'] += $amount;
    }

    private function applySums(array &$values, array $definitions): void
    {
        foreach ($definitions as $id => $children) $values[$id] = array_sum(array_map(static fn (string $child): float => (float) ($values[$child] ?? 0), $children));
    }

    private function delta(array $byMonth, string $section, string $id, string $from, string $to): float
    {
        $sum = 0.0;
        foreach ($byMonth as $month => $data) if ($month >= $from && $month <= $to) $sum += (float) ($data[$section][$id] ?? 0);
        return $sum;
    }

    private function periodsBetween(string $start, string $end): array
    {
        $periods = [];
        $cursor = new \DateTimeImmutable($start.'-01');
        $last = new \DateTimeImmutable($end.'-01');
        while ($cursor <= $last) {
            $periods[] = $cursor->format('Y-m');
            $cursor = $cursor->modify('+1 month');
        }
        return $periods;
    }

    private function selectValues(array $lines, string $period, string $mode): array
    {
        return array_map(static function (array $line) use ($period, $mode): array {
            $line['value'] = $mode === 'ytd' ? ($line['ytd'] ?? null) : ($line['months'][$period] ?? null);
            return $line;
        }, $lines);
    }
}
