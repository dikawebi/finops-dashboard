<?php

namespace App\Services;

use App\Models\FinancialBaseline;
use App\Models\GlEntry;
use Illuminate\Support\Facades\DB;

class FinancialStatementService
{
    public function build(string $period, string $mode = 'mtd', ?CostMatrixService $costs = null): array
    {
        $mode = strtolower($mode) === 'ytd' ? 'ytd' : 'mtd';
        $baselinePath = base_path("../baseline/reference_dump/financial-statements_{$period}_{$mode}.json");
        $payload = is_file($baselinePath)
            ? json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR)['data'] ?? []
            : $this->baselinePayload($period, $mode, $costs);

        if (!$payload) return ['period' => $period, 'mode' => $mode, 'sections' => []];

        $report = $payload['report'] ?? [];
        $glStart = $report['glStartPeriod'] ?? '2026-10';
        $glMonths = $this->syncedMonths($period, $glStart);
        if (!$glMonths) {
            return $this->response($payload, $period, $mode, 'financial-baseline');
        }

        $profitLoss = $report['profitLoss'] ?? [];
        $balanceSheet = $report['balanceSheet'] ?? [];
        $existingMonths = $report['periods'] ?? [];
        $accountBalances = $payload['accountBalances'] ?? [];
        $unmappedAccounts = [];
        $movementByAccount = [];
        $movementByMonth = [];
        $knownAccountCodes = array_fill_keys(array_map(static fn (array $row): string => (string) ($row['code'] ?? ''), $accountBalances), true);

        foreach ($glMonths as $month => $data) {
            $movementByMonth[$month] = $data['accounts'];
            foreach ($data['accounts'] as $code => $amount) {
                $movementByAccount[$code] = ($movementByAccount[$code] ?? 0) + $amount;
            }
        }

        // Keep the official September closing balances as opening values, then replace
        // the reference snapshot's GL movements with the currently synced GL rows.
        $mappedAccounts = [];
        foreach ($accountBalances as &$account) {
            $code = (string) ($account['code'] ?? '');
            $mapping = (string) ($account['mapping'] ?? '');
            $reportLine = $this->lineIdForLabel($balanceSheet, $mapping);
            $rawMovement = (float) ($movementByAccount[$code] ?? 0);
            $movement = $this->isLiabilityOrEquity($balanceSheet, $reportLine) ? -$rawMovement : $rawMovement;
            $account['glMovementUSD'] = $movement;
            $account['closingUSD'] = (float) ($account['baseUSD'] ?? 0) + $movement;
            if ($reportLine !== null) {
                $mappedAccounts[$reportLine] = ($mappedAccounts[$reportLine] ?? 0) + $account['closingUSD'];
            }
        }
        unset($account);

        foreach ($movementByAccount as $code => $amount) {
            if (!isset($knownAccountCodes[$code]) && $this->profitLossLine((string) $code, '') === null) {
                $unmappedAccounts[] = ['code' => $code, 'month' => $period, 'amountUSD' => $amount];
            }
        }

        $plMovements = [];
        foreach ($movementByMonth as $month => $accounts) {
            $monthData = $glMonths[$month];
            $plMovements[$month] = [];
            foreach ($monthData['entries'] as $entry) {
                $lineId = $this->profitLossLine((string) $entry['code'], (string) $entry['costingTo']);
                if ($lineId !== null) $plMovements[$month][$lineId] = ($plMovements[$month][$lineId] ?? 0) - $entry['amount'];
            }
        }

        foreach ($profitLoss as &$line) {
            $id = $line['id'] ?? '';
            foreach ($glMonths as $month => $data) {
                if (in_array($month, $existingMonths, true)) {
                    $line['months'][$month] = (float) ($plMovements[$month][$id] ?? 0);
                }
            }
            if ($mode === 'ytd') {
                $line['ytd'] = (float) ($line['ytd'] ?? 0) + array_sum(array_map(
                    static fn (string $month): float => (float) ($plMovements[$month][$id] ?? 0),
                    array_keys($glMonths)
                ));
            }
        }
        unset($line);
        $this->recalculateProfitLoss($profitLoss, $mode, $period);

        foreach ($balanceSheet as &$line) {
            $id = $line['id'] ?? '';
            if (($line['kind'] ?? '') === 'detail' || isset($mappedAccounts[$id])) {
                $line['ytd'] = (float) ($mappedAccounts[$id] ?? 0);
                $cumulativeMovement = 0.0;
                foreach ($existingMonths as $month) {
                    if ($month < $glStart || $month > $period) continue;
                    $monthMovement = 0.0;
                    foreach ($accountBalances as $account) {
                        if ($this->lineIdForLabel($balanceSheet, (string) ($account['mapping'] ?? '')) !== $id) continue;
                        $monthMovement += (float) ($movementByMonth[$month][(string) $account['code']] ?? 0)
                            * ($this->isLiabilityOrEquity($balanceSheet, $id) ? -1 : 1);
                    }
                    $cumulativeMovement += $monthMovement;
                    $line['months'][$month] = (float) array_sum(array_map(
                        static fn (array $account): float => strcasecmp((string) ($account['mapping'] ?? ''), (string) ($line['label'] ?? '')) === 0
                            ? (float) ($account['baseUSD'] ?? 0) : 0.0,
                        $accountBalances
                    )) + $cumulativeMovement;
                }
            }
        }
        unset($line);
        $this->recalculateBalanceSheet($balanceSheet);

        $report['profitLoss'] = $profitLoss;
        $report['balanceSheet'] = $balanceSheet;
        $report['glMonths'] = array_replace($report['glMonths'] ?? [], array_map(
            static fn (array $data): array => ['rows' => $data['rows'], 'ledgerSumUSD' => $data['sum']],
            $glMonths
        ));
        $payload['report'] = $report;
        $payload['accountBalances'] = $accountBalances;
        $payload['diagnostics']['unmappedAccounts'] = $unmappedAccounts;
        $payload['diagnostics']['source'] = 'gl_entries (D365) + financial baseline opening balances';
        $payload['diagnostics']['syncedGLRows'] = array_sum(array_column($glMonths, 'rows'));
        $payload['sections'] = [
            'profitLoss' => $this->selectValues($profitLoss, $period, $mode),
            'balanceSheet' => $this->selectValues($balanceSheet, $period, $mode),
        ];
        $payload['incomeStatement'] = $this->incomeSummary($profitLoss, $period, $mode, $payload['currency'] ?? 'USD');
        $payload['balanceSheet'] = $payload['sections']['balanceSheet'];

        return $this->response($payload, $period, $mode, 'd365-gl+financial-baseline');
    }

    private function baselinePayload(string $period, string $mode, ?CostMatrixService $costs): array
    {
        $baseline = FinancialBaseline::first();
        if (!$baseline) return [];
        $sections = [];
        foreach (($baseline->sections ?? []) as $name => $lines) {
            $sections[$name] = array_map(static function (array $line) use ($period, $mode): array {
                $line['value'] = $mode === 'ytd' ? ($line['baselineYTD'] ?? 0) : ($line['months'][$period] ?? 0);
                return $line;
            }, $lines);
        }
        $matrix = $costs?->build($period, $mode);
        $total = (float) ($matrix['grandTotal']['allCost'] ?? 0);
        $selling = (float) ($matrix['grandTotal']['sellingExpense'] ?? 0);
        return ['currency' => $baseline->currency, 'report' => $sections, 'sections' => $sections,
            'cashFlowEnabled' => (bool) $baseline->cash_flow_enabled, 'accountBalances' => [],
            'diagnostics' => ['cashFlowEnabled' => (bool) $baseline->cash_flow_enabled],
            'incomeStatement' => ['operatingExpenses' => ['generalAdministrative' => $total - $selling, 'sellingExpense' => $selling, 'totalOpex' => $total]],
            'inventoryReconciliation' => ['status' => 'provisional']];
    }

    private function syncedMonths(string $period, string $start): array
    {
        if ($period < $start) return [];
        $end = $period;
        return DB::table('gl_entries')->where('source_type', 'd365')->whereBetween('period', [$start, $end])
            ->selectRaw('period, main_account, costing_to, SUM(COALESCE(amount_reporting, amount, 0)) as amount, COUNT(*) as row_count')
            ->groupBy('period', 'main_account', 'costing_to')->orderBy('period')->get()
            ->groupBy('period')->map(static function ($rows): array {
                $accounts = [];
                $entries = [];
                $count = 0;
                $sum = 0.0;
                foreach ($rows as $row) {
                    $code = (string) $row->main_account;
                    $accounts[$code] = ($accounts[$code] ?? 0) + (float) $row->amount;
                    $entries[] = ['code' => (string) $row->main_account, 'costingTo' => (string) ($row->costing_to ?? ''), 'amount' => (float) $row->amount];
                    $count += (int) $row->row_count;
                    $sum += (float) $row->amount;
                }
                return ['accounts' => $accounts, 'entries' => $entries, 'rows' => $count, 'sum' => $sum];
            })->all();
    }

    private function profitLossLine(string $code, string $costingTo): ?string
    {
        if (str_starts_with($code, '3')) return 'coalSales';
        if (str_starts_with($code, '41')) return 'costOfCoalSold';
        if (in_array($code, ['6000003', '6000004'], true)) return 'royaltyCoalSales';
        if (in_array($code, ['7000001', '7000002', '7000003', '7006001', '7006002', '7007001'], true)) return 'financeIncome';
        if ($code === '7002002') return 'realisedFX';
        if ($code === '7002001') return 'unrealisedFX';
        if (str_starts_with($code, '7001') || str_starts_with($code, '7003') || str_starts_with($code, '7004') || str_starts_with($code, '8')) return 'financeCost';
        if (str_starts_with($code, '6')) return 'sellingExpense';
        if (str_starts_with($code, '4') || str_starts_with($code, '5') || str_starts_with($code, '9')) {
            return in_array(strtolower(trim($costingTo)), ['selling', 'marketing', 'selling expense'], true) ? 'sellingExpense' : 'generalAdmin';
        }
        return null;
    }

    private function lineIdForLabel(array $lines, string $label): ?string
    {
        foreach ($lines as $line) if (strcasecmp(trim((string) ($line['label'] ?? '')), trim($label)) === 0) return $line['id'] ?? null;
        return null;
    }

    private function isLiabilityOrEquity(array $lines, ?string $id): bool
    {
        $liabilities = false;
        foreach ($lines as $line) {
            if (($line['id'] ?? '') === 'heading-45') $liabilities = true;
            if (($line['id'] ?? '') === $id) return $liabilities;
        }
        return false;
    }

    private function recalculateProfitLoss(array &$lines, string $mode, string $period): void
    {
        $ids = [];
        foreach ($lines as &$line) $ids[$line['id']] =& $line;
        unset($line);
        $months = array_keys($lines[0]['months'] ?? []);
        $calculate = static function (string $month) use (&$ids): void {
            $get = static fn (string $id): float => (float) ($ids[$id]['months'][$month] ?? 0);
            $set = static function (string $id, float $value) use (&$ids, $month): void { $ids[$id]['months'][$month] = $value; };
            $set('grossProfit', $get('coalSales') + $get('costOfCoalSold'));
            $set('profitBeforeInterest', $get('grossProfit') + $get('sellingExpense') + $get('royaltyCoalSales') + $get('generalAdmin'));
            $set('profitBeforeTax', $get('profitBeforeInterest') + $get('financeIncome') + $get('financeCost') + $get('realisedFX') + $get('unrealisedFX'));
            $set('profitForYear', $get('profitBeforeTax') + $get('incomeTax'));
        };
        foreach ($months as $month) $calculate($month);
        if (isset($ids['grossProfit'])) {
            foreach (['coalSales','costOfCoalSold','grossProfit','sellingExpense','royaltyCoalSales','generalAdmin','profitBeforeInterest','financeIncome','financeCost','realisedFX','unrealisedFX','profitBeforeTax','incomeTax','profitForYear'] as $id) {
                if (!isset($ids[$id])) continue;
                $ids[$id]['ytd'] = array_sum(array_map(static fn (string $month): float => (float) ($ids[$id]['months'][$month] ?? 0), $months));
            }
        }
    }

    private function recalculateBalanceSheet(array &$lines): void
    {
        $keys = ['ytd'];
        foreach ($lines as $line) foreach (array_keys($line['months'] ?? []) as $month) $keys[$month] = $month;
        foreach ($keys as $key) {
            $values = [];
            foreach ($lines as $line) if (($line['kind'] ?? '') === 'detail' || $line['id'] === 'totalEquity') $values[$line['id']] = (float) ($line[$key] ?? $line['months'][$key] ?? 0);
            $values['cashAndEquivalents'] = ($values['cashOnHand'] ?? 0) + ($values['cashInBanks'] ?? 0) + ($values['timeDeposits'] ?? 0);
            $values['prepaidTaxesAdvances'] = ($values['advances'] ?? 0) + ($values['prepayments'] ?? 0) + ($values['prepaidTaxes'] ?? 0);
            $values['totalCurrentAssets'] = ($values['cashAndEquivalents'] ?? 0) + ($values['tradeReceivables'] ?? 0) + ($values['prepaidTaxesAdvances'] ?? 0) + ($values['inventories'] ?? 0) + ($values['derivativeAsset'] ?? 0);
            $values['totalNonCurrentAssets'] = ($values['restrictedCash'] ?? 0) + ($values['miningProperties'] ?? 0) + ($values['explorationAssets'] ?? 0) + ($values['fixedAssets'] ?? 0) + ($values['retirementAsset'] ?? 0) + ($values['deferredTaxAsset'] ?? 0);
            $values['totalAssets'] = $values['totalCurrentAssets'] + $values['totalNonCurrentAssets'];
            $values['totalCurrentLiabilities'] = ($values['loansCurrent'] ?? 0) + ($values['financeLeaseCurrent'] ?? 0) + ($values['tradePayables'] ?? 0) + ($values['accruedExpenses'] ?? 0) + ($values['taxesPayable'] ?? 0) + ($values['advancesCustomer'] ?? 0) + ($values['otherCurrentLiabilities'] ?? 0) + ($values['derivativeLiability'] ?? 0);
            $values['totalNonCurrentLiabilities'] = ($values['loansNonCurrent'] ?? 0) + ($values['financeLeaseNonCurrent'] ?? 0) + ($values['employeeBenefits'] ?? 0) + ($values['reclamationProvision'] ?? 0) + ($values['deferredTaxLiabilities'] ?? 0);
            $values['totalLiabilities'] = $values['totalCurrentLiabilities'] + $values['totalNonCurrentLiabilities'];
            $values['totalLiabilitiesEquity'] = $values['totalLiabilities'] + ($values['totalEquity'] ?? 0);
            foreach ($lines as &$line) {
                if (!isset($values[$line['id']])) continue;
                if ($key === 'ytd') $line['ytd'] = $values[$line['id']];
                else $line['months'][$key] = $values[$line['id']];
            }
            unset($line);
        }
    }

    private function selectValues(array $lines, string $period, string $mode): array
    {
        return array_map(static function (array $line) use ($period, $mode): array {
            $line['value'] = $mode === 'ytd' ? ($line['ytd'] ?? null) : ($line['months'][$period] ?? null);
            return $line;
        }, $lines);
    }

    private function incomeSummary(array $lines, string $period, string $mode, string $currency): array
    {
        $value = [];
        foreach ($lines as $line) $value[$line['id']] = (float) ($mode === 'ytd' ? ($line['ytd'] ?? 0) : ($line['months'][$period] ?? 0));
        $revenue = $value['coalSales'] ?? 0;
        $gross = $value['grossProfit'] ?? 0;
        $beforeInterest = $value['profitBeforeInterest'] ?? 0;
        $beforeTax = $value['profitBeforeTax'] ?? 0;
        return ['period' => $period, 'currency' => $currency, 'salesVolumeMT' => null,
            'revenue' => ['totalRevenue' => $revenue], 'cogs' => ['totalCOGS' => $value['costOfCoalSold'] ?? 0],
            'grossProfit' => $gross, 'grossProfitMarginPct' => $revenue == 0 ? 0 : $gross / $revenue * 100,
            'operatingExpenses' => ['generalAdministrative' => $value['generalAdmin'] ?? 0, 'sellingExpense' => $value['sellingExpense'] ?? 0,
                'royaltyCoalSales' => $value['royaltyCoalSales'] ?? 0, 'totalOpex' => ($value['generalAdmin'] ?? 0) + ($value['sellingExpense'] ?? 0) + ($value['royaltyCoalSales'] ?? 0)],
            'operatingProfitEBITDA' => $beforeInterest, 'ebitdaMarginPct' => $revenue == 0 ? 0 : $beforeInterest / $revenue * 100,
            'otherItems' => ['financeIncome' => $value['financeIncome'] ?? 0, 'financeCost' => $value['financeCost'] ?? 0, 'forexGainLoss' => ($value['realisedFX'] ?? 0) + ($value['unrealisedFX'] ?? 0), 'includedInGA' => false],
            'netProfitBeforeTax' => $beforeTax, 'netProfitAfterTax' => $value['profitForYear'] ?? $beforeTax,
            'netProfitMarginPct' => $revenue == 0 ? 0 : $beforeTax / $revenue * 100];
    }

    private function response(array $payload, string $period, string $mode, string $source): array
    {
        $payload['period'] = $period;
        $payload['mode'] = $mode;
        $payload['dataSource'] = $source;
        $report = $payload['report'] ?? [];
        if (isset($report['profitLoss'], $report['balanceSheet'])) {
            $payload['sections'] = [
                'profitLoss' => $this->selectValues($report['profitLoss'], $period, $mode),
                'balanceSheet' => $this->selectValues($report['balanceSheet'], $period, $mode),
            ];
            $payload['balanceSheet'] = $payload['sections']['balanceSheet'];
        } elseif (!isset($payload['sections'])) {
            $payload['sections'] = $report;
        }
        return $payload;
    }
}
