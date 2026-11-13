<?php

namespace App\Services;

use App\Models\Accrual;
use App\Models\AccrualHistory;
use App\Models\CostAccount;
use App\Models\GlEntry;
use App\Models\SystemConfig;
use App\Services\D365\D365AllocationService;

/** Laravel port of the reference project's computeCostMatrix formula. */
class CostMatrixService
{
    private const FIELDS = [
        'allCost','cek','valMining','valHauling','valWP','valPort','valNotValuated','totalValuation',
        'costMining','costHauling','costHaulingBUAToWP','costHaulingBUAToPort','costHaulingWPToPort',
        'unallocatedHauling','costWP','costPort','costGA','costSelling','totalCosting','totalCOGP',
        'directGA','sellingExpense','totalExpense',
    ];

    public function __construct(private readonly D365AllocationService $allocation) {}

    public function build(string $period, string $mode = 'mtd'): array
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);
        $periods = $this->periods($period, $mode);
        $rows = $this->emptyRows();
        $glEntries = GlEntry::whereIn('period', $periods)->get();
        // As in the reference formula, any imported GL row marks that month as
        // GL-backed and replaces that month's workbook cost history.
        $monthsWithGl = array_fill_keys($glEntries->pluck('period')->filter()->unique()->all(), true);
        $history = $this->historicalEntries($periods, $monthsWithGl);
        $closedPeriods = array_fill_keys($this->closedPeriods(), true);
        $accruals = array_values(array_filter($this->accrualEntries($periods), static fn (array $entry): bool => !isset($closedPeriods[(string) ($entry['period'] ?? '')])));

        foreach ($glEntries as $entry) {
            $code = trim((string) $entry->main_account);
            if (!preg_match('/^[5-9]/', $code)) continue;
            $this->ensureRow($rows, $code, (string) ($entry->account_name ?? ''));
            if ($entry->source_type === 'd365') {
                $mapping = $this->allocation->resolveGlEntry($entry);
                $entry->valuation_to = $mapping['valuation_to'];
                $entry->costing_to = $mapping['costing_to'];
            }
            $this->allocate($rows[$code], $entry, (string) ($entry->amount_reporting ?? '0'));
        }

        foreach ($history as $entry) {
            $code = trim((string) ($entry['MainAccount'] ?? ''));
            if (!preg_match('/^[5-9]/', $code)) continue;
            $this->ensureRow($rows, $code, (string) ($entry['AccountName'] ?? ''));
            $model = new GlEntry($entry);
            $model->main_account = $code;
            $model->valuation_to = $entry['Valuation to'] ?? null;
            $model->costing_to = $entry['Costing to'] ?? null;
            $amount = number_format((float) ($entry['Amount in reporting currency'] ?? 0), 6, '.', '');
            $this->allocate($rows[$code], $model, $amount);
        }

        foreach ($accruals as $entry) {
            $month = (string) ($entry['period'] ?? '');
            if ($month === '') continue;
            $code = trim((string) ($entry['account_code'] ?? $entry['code'] ?? ''));
            if (!preg_match('/^[5-9]/', $code)) continue;
            $this->ensureRow($rows, $code, (string) ($entry['name'] ?? $entry['description'] ?? ''));
            $model = new GlEntry();
            $model->main_account = $code;
            $model->costing_to = $entry['category'] ?? $entry['costing_to'] ?? 'Mining';
            $model->valuation_to = null;
            $this->allocate($rows[$code], $model, number_format((float) ($entry['amount_usd'] ?? $entry['amount'] ?? 0), 6, '.', ''));
        }

        foreach ($rows as &$row) $this->finish($row);
        unset($row);

        $parents = array_keys(array_filter($rows, static fn (array $row): bool => $row['isParent']));
        usort($parents, fn (string $a, string $b): int => $this->hierarchyDepth($rows, $b) <=> $this->hierarchyDepth($rows, $a));
        foreach ($parents as $code) {
            $parentCode = (string) $code;
            $children = array_filter($rows, static fn (array $row): bool => (string) $row['_parent'] === $parentCode);
            foreach (self::FIELDS as $field) $rows[$code][$field] = $this->sum(array_column($children, $field));
        }

        $public = array_map(static function (array $row): array {
            if ($row['_parent'] !== null) $row['parent'] = $row['_parent'];
            return array_diff_key($row, ['_parent' => true]);
        }, array_values($rows));
        $grand = array_fill_keys(self::FIELDS, '0');
        foreach ($public as $row) {
            if ($row['isParent']) continue;
            foreach (self::FIELDS as $field) $grand[$field] = bcadd($grand[$field], (string) $row[$field], 6);
        }

        $historyMonths = array_values(array_unique(array_column($history, 'period')));
        $rawMonths = array_keys($monthsWithGl);
        $source = $historyMonths && $rawMonths ? 'mixed' : ($historyMonths ? 'workbook' : ($rawMonths ? 'gl' : 'none'));
        $accrualMonths = array_values(array_unique(array_filter(array_column($accruals, 'period'))));
        $capitalizationPeriods = $this->sourceFilePeriods('../data_snapshot/extracted/mining_capitalization_history.json', $periods, $monthsWithGl);
        $adjustmentPeriods = $this->sourceFilePeriods('../data_snapshot/extracted/cost_report_adjustments.json', $periods, $monthsWithGl);

        return [
            'currency' => 'USD', 'amountScale' => 1, 'mode' => $mode, 'period' => $period,
            'dataSource' => $source, 'capitalizationPeriods' => $capitalizationPeriods,
            'adjustmentPeriods' => $adjustmentPeriods, 'accrualPeriods' => $accrualMonths,
            'historicalPeriods' => $historyMonths, 'periods' => $periods,
            'closedPeriods' => array_values(array_intersect($periods, array_keys($closedPeriods))),
            'reportCostClosed' => isset($closedPeriods[$period]), 'rows' => $public,
            'grandTotal' => $this->finishGrandTotal($grand),
        ];
    }

    private function emptyRows(): array
    {
        $result = [];
        foreach (CostAccount::orderBy('account_code')->get() as $account) {
            $row = ['code' => (string) $account->account_code, 'name' => $account->account_name,
                'isParent' => (bool) $account->is_parent, '_parent' => $account->parent_code];
            foreach (self::FIELDS as $field) $row[$field] = '0';
            $result[$row['code']] = $row;
        }
        return $result;
    }

    private function ensureRow(array &$rows, string $code, string $name = ''): void
    {
        if (isset($rows[$code])) {
            if ($rows[$code]['name'] === '' && $name !== '') $rows[$code]['name'] = $name;
            return;
        }
        $rows[$code] = ['code' => $code, 'name' => $name !== '' ? $name : "Account {$code}", 'isParent' => false, '_parent' => null];
        foreach (self::FIELDS as $field) $rows[$code][$field] = '0';
    }

    private function allocate(array &$row, GlEntry $entry, string $amount): void
    {
        $head = substr((string) $entry->main_account, 0, 1);
        $valuation = strtolower(trim((string) $entry->valuation_to));
        $costing = strtolower(trim((string) $entry->costing_to));
        $row['allCost'] = bcadd($row['allCost'], $amount, 6);

        // 7–9 always report to GA regardless of allocation dimensions.
        if (in_array($head, ['7','8','9'], true)) {
            $row['costGA'] = bcadd($row['costGA'], $amount, 6);
            return;
        }

        $isDirect = preg_replace('/[\s_-]+/', '', $costing) === 'directexpense';
        if ($isDirect) {
            if (str_contains($valuation, 'mining') || str_contains($valuation, 'pit')) $row['valMining'] = bcadd($row['valMining'], $amount, 6);
            elseif (str_contains($valuation, 'haul')) $row['valHauling'] = bcadd($row['valHauling'], $amount, 6);
            elseif (str_contains($valuation, 'wash') || str_contains($valuation, 'wp')) $row['valWP'] = bcadd($row['valWP'], $amount, 6);
            elseif (str_contains($valuation, 'port') || str_contains($valuation, 'jetty')) $row['valPort'] = bcadd($row['valPort'], $amount, 6);
            elseif (preg_replace('/[\s_-]+/', '', $valuation) === 'notvaluated') $row['valNotValuated'] = bcadd($row['valNotValuated'], $amount, 6);
            return;
        }

        if (str_contains($costing, 'mining') || str_contains($costing, 'pit')) $row['costMining'] = bcadd($row['costMining'], $amount, 6);
        elseif (str_contains($costing, 'haul')) {
            $row['costHauling'] = bcadd($row['costHauling'], $amount, 6);
            $route = $this->haulingRoute($costing);
            if ($route === 'bua_wp') $row['costHaulingBUAToWP'] = bcadd($row['costHaulingBUAToWP'], $amount, 6);
            elseif ($route === 'bua_port') $row['costHaulingBUAToPort'] = bcadd($row['costHaulingBUAToPort'], $amount, 6);
            elseif ($route === 'wp_port') $row['costHaulingWPToPort'] = bcadd($row['costHaulingWPToPort'], $amount, 6);
        } elseif (str_contains($costing, 'wash') || str_contains($costing, 'wp')) $row['costWP'] = bcadd($row['costWP'], $amount, 6);
        elseif (str_contains($costing, 'port') || str_contains($costing, 'jetty')) $row['costPort'] = bcadd($row['costPort'], $amount, 6);
        elseif ($costing === 'ga' || str_contains($costing, 'admin') || str_contains($costing, 'overhead')) $row['costGA'] = bcadd($row['costGA'], $amount, 6);
        elseif (str_contains($costing, 'sell') || str_contains($costing, 'market') || str_contains($costing, 'mkt')) $row['costSelling'] = bcadd($row['costSelling'], $amount, 6);
    }

    private function finish(array &$row): void
    {
        $row['totalValuation'] = $this->sum([$row['valMining'],$row['valHauling'],$row['valWP'],$row['valPort'],$row['valNotValuated']]);
        $row['unallocatedHauling'] = bcsub($row['costHauling'], $this->sum([$row['costHaulingBUAToWP'],$row['costHaulingBUAToPort'],$row['costHaulingWPToPort']]), 6);
        $row['totalCosting'] = $this->sum([$row['costMining'],$row['costHauling'],$row['costWP'],$row['costPort'],$row['costGA'],$row['costSelling']]);
        $row['totalCOGP'] = $this->sum([$row['costMining'],$row['costHauling'],$row['costWP'],$row['costPort']]);
        $row['directGA'] = $this->sum([$row['valNotValuated'],$row['costGA']]);
        $row['sellingExpense'] = $row['costSelling'];
        $row['totalExpense'] = $this->sum([$row['totalValuation'],$row['costGA'],$row['costSelling']]);
        $row['cek'] = '0';
    }

    private function finishGrandTotal(array $grand): array
    {
        $grand['unallocatedHauling'] = bcsub($grand['costHauling'], $this->sum([$grand['costHaulingBUAToWP'],$grand['costHaulingBUAToPort'],$grand['costHaulingWPToPort']]), 6);
        $grand['totalCOGP'] = $this->sum([$grand['costMining'],$grand['costHauling'],$grand['costWP'],$grand['costPort']]);
        $grand['directGA'] = $this->sum([$grand['valNotValuated'],$grand['costGA']]);
        $grand['sellingExpense'] = $grand['costSelling'];
        $grand['totalExpense'] = $this->sum([$grand['totalValuation'],$grand['costGA'],$grand['costSelling']]);
        return $grand;
    }

    private function historicalEntries(array $periods, array $monthsWithGl): array
    {
        $path = base_path('../data_snapshot/extracted/cost_report_history.json');
        if (!is_file($path)) return [];
        $source = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $capitalizationPath = base_path('../data_snapshot/extracted/mining_capitalization_history.json');
        $capitalization = is_file($capitalizationPath) ? (json_decode(file_get_contents($capitalizationPath), true, 512, JSON_THROW_ON_ERROR)['periods'] ?? []) : [];
        $adjustmentPath = base_path('../data_snapshot/extracted/cost_report_adjustments.json');
        $adjustments = is_file($adjustmentPath) ? (json_decode(file_get_contents($adjustmentPath), true, 512, JSON_THROW_ON_ERROR)['periods'] ?? []) : [];
        $entries = [];
        foreach ($periods as $month) {
            if (isset($monthsWithGl[$month]) || $month >= '2026-10') continue;
            foreach (($source['periods'][$month]['entries'] ?? []) as $entry) $entries[] = $entry + ['period' => $month];
            foreach ($capitalization[$month] ?? [] as $entry) $entries[] = $entry + ['period' => $month];
            foreach ($adjustments[$month] ?? [] as $entry) $entries[] = $entry + ['period' => $month];
        }
        return $entries;
    }

    private function accrualEntries(array $periods): array
    {
        $entries = Accrual::whereIn('period', $periods)->get()->map(fn (Accrual $entry): array => [
            'period' => $entry->period, 'account_code' => $entry->account_code, 'name' => $entry->description,
            'category' => $entry->category, 'amount_usd' => $entry->amount_usd,
        ])->all();

        $historyFile = base_path('../data_snapshot/extracted/automatic_accrual_history.json');
        $historyData = is_file($historyFile) ? (json_decode(file_get_contents($historyFile), true, 512, JSON_THROW_ON_ERROR)['periods'] ?? []) : [];
        $dbHistory = AccrualHistory::whereIn('period', $periods)->get()->keyBy('period');
        foreach ($periods as $month) {
            $snapshot = $dbHistory->get($month)?->payload ?? ($historyData[$month] ?? []);
            foreach ((array) $snapshot as $row) $entries[] = $row + [
                'period' => $month, 'account_code' => $row['code'] ?? null, 'amount_usd' => $row['amount'] ?? 0,
            ];
        }
        return $entries;
    }

    private function sourceFilePeriods(string $relativePath, array $periods, array $monthsWithGl): array
    {
        $path = base_path($relativePath);
        if (!is_file($path)) return [];
        $source = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['periods'] ?? [];
        return array_values(array_filter($periods, static fn (string $month): bool => !isset($monthsWithGl[$month]) && !empty($source[$month])));
    }

    private function haulingRoute(string $costing): ?string
    {
        $route = str_replace(['washing plant','wash plant','bp port','→','➜','➝'], ['wp','wp','port','to','to','to'], strtolower($costing));
        $route = preg_replace('/\s*->\s*/', 'to', $route);
        $route = preg_replace('/[^a-z]/', '', $route);
        $route = preg_replace('/^(coal)?hauling/', '', $route);
        return match ($route) { 'buatowp' => 'bua_wp', 'buatoport' => 'bua_port', 'wptoport' => 'wp_port', default => null };
    }

    private function sum(array $values): string
    {
        $total = '0';
        foreach ($values as $value) $total = bcadd($total, (string) $value, 6);
        return $total;
    }

    private function hierarchyDepth(array $rows, string $code): int
    {
        $depth = 0; $seen = [];
        while (isset($rows[$code]['_parent']) && $rows[$code]['_parent'] !== null && !isset($seen[$code])) {
            $seen[$code] = true; $code = (string) $rows[$code]['_parent']; $depth++;
        }
        return $depth;
    }

    private function periods(string $period, string $mode): array
    {
        if ($mode !== 'ytd') return [$period];
        [$year] = explode('-', $period);
        return array_map(fn (int $month): string => sprintf('%s-%02d', $year, $month), range(1, (int) substr($period, 5, 2)));
    }

    private function closedPeriods(): array
    {
        $path = base_path('../data_snapshot/extracted/report_cost_closing.json');
        $referencePeriods = is_file($path)
            ? (json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['periods'] ?? [])
            : [];
        $configured = SystemConfig::query()->where('key', 'locked_periods')->first()?->value ?? [];
        if (is_string($configured)) $configured = json_decode($configured, true) ?? [];
        if (!is_array($configured)) $configured = [];
        $closed = array_keys(array_filter($referencePeriods, static fn ($isClosed): bool => (bool) $isClosed));
        return array_values(array_unique(array_merge($closed, array_map('strval', $configured))));
    }
}
