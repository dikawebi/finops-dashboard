<?php

namespace App\Services;

use App\Models\Accrual;
use App\Models\AccrualHistory;
use App\Models\CostAccount;
use App\Models\GlEntry;
use App\Models\SystemConfig;
use App\Services\D365\D365AllocationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

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

    public function build(string $period, string $mode = 'mtd', bool $includeAccruals = true): array
    {
        $periods = $this->periods($period, $mode);
        $fingerprint = $this->sourceFingerprint($periods);
        $key = 'cost-matrix:'.hash('sha256', $period.'|'.$mode.'|'.(int) $includeAccruals.'|'.$fingerprint);
        return Cache::remember($key, now()->addSeconds(60), fn (): array => $this->compute($period, $mode, $includeAccruals));
    }

    private function compute(string $period, string $mode, bool $includeAccruals): array
    {
        @ini_set('memory_limit', '512M');
        @set_time_limit(120);
        $periods = $this->periods($period, $mode);
        $rows = $this->emptyRows();
        $glEntries = \Illuminate\Support\Facades\DB::table('gl_entries')->whereIn('period', $periods)
            ->where('period', '>=', '2026-10')->where('source_type', 'd365')->get([
            'period','main_account','account_name','source_type','amount_reporting','valuation_to','costing_to',
            'activity','location','department','group_activity',
        ]);
        // As in the reference formula, any imported GL row marks that month as
        // GL-backed and replaces that month's workbook cost history.
        $monthsWithGl = array_fill_keys($glEntries->pluck('period')->filter()->unique()->all(), true);
        $history = $this->historicalEntries($periods, $monthsWithGl);
        $closedPeriods = array_fill_keys($this->closedPeriods(), true);
        $accruals = $includeAccruals
            ? array_values(array_filter($this->accrualEntries($periods), static fn (array $entry): bool => !isset($closedPeriods[(string) ($entry['period'] ?? '')])))
            : [];

        $mappingCache = [];
        foreach ($glEntries as $entry) {
            $code = trim((string) $entry->main_account);
            if (!preg_match('/^[5-9]/', $code)) continue;
            $this->ensureRow($rows, $code, (string) ($entry->account_name ?? ''));
            if ($entry->source_type === 'd365') {
                $mappingKey = implode('|', array_map('strval', [
                    $entry->main_account, $entry->activity, $entry->location,
                    $entry->department, $entry->group_activity,
                ]));
                $mapping = $mappingCache[$mappingKey] ??= $this->allocation->resolveGlEntry($entry);
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
        $d365Months = $glEntries->where('source_type', 'd365')->pluck('period')->filter()->unique()->sort()->values()->all();
        $source = $historyMonths && $rawMonths ? 'mixed' : ($historyMonths ? 'workbook' : ($rawMonths ? 'gl' : 'none'));
        $accrualMonths = array_values(array_unique(array_filter(array_column($accruals, 'period'))));
        $capitalizationPeriods = $this->sourceFilePeriods('../data_snapshot/extracted/mining_capitalization_history.json', $periods, $monthsWithGl);
        $adjustmentPeriods = $this->sourceFilePeriods('../data_snapshot/extracted/cost_report_adjustments.json', $periods, $monthsWithGl);

        return [
            'currency' => 'USD', 'amountScale' => 1, 'mode' => $mode, 'period' => $period,
            'dataSource' => $source, 'capitalizationPeriods' => $capitalizationPeriods,
            'd365Periods' => $d365Months,
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

    /** Return the report bucket used by the cost matrix for a mapped GL entry. */
    public function reportBucket(object $entry): ?string
    {
        $code = trim((string) ($entry->main_account ?? ''));
        if (! preg_match('/^[5-9]/', $code)) return null;

        $head = substr($code, 0, 1);
        $valuation = strtolower(trim((string) ($entry->valuation_to ?? '')));
        $costing = strtolower(trim((string) ($entry->costing_to ?? '')));

        if (in_array($head, ['7', '8', '9'], true)) return 'expense';

        if (preg_replace('/[\s_-]+/', '', $costing) === 'directexpense') {
            if (str_contains($valuation, 'mining') || str_contains($valuation, 'pit')
                || str_contains($valuation, 'haul') || str_contains($valuation, 'wash')
                || str_contains($valuation, 'wp') || str_contains($valuation, 'port')
                || str_contains($valuation, 'jetty')
                || preg_replace('/[\s_-]+/', '', $valuation) === 'notvaluated') {
                return 'expense';
            }
            return null;
        }

        if (str_contains($costing, 'mining') || str_contains($costing, 'pit')
            || str_contains($costing, 'haul') || str_contains($costing, 'wash')
            || str_contains($costing, 'wp') || str_contains($costing, 'port')
            || str_contains($costing, 'jetty')) return 'cogp';

        if ($costing === 'ga' || str_contains($costing, 'admin')
            || str_contains($costing, 'overhead') || str_contains($costing, 'sell')
            || str_contains($costing, 'market') || str_contains($costing, 'mkt')) return 'expense';

        return null;
    }

    private function allocate(array &$row, object $entry, string $amount): void
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
        if (!$periods || count(array_filter($periods, static fn (string $month): bool => $month < '2026-10')) === 0) return [];
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
            'category' => $entry->category, 'amount_usd' => $entry->amount_usd, 'source'=>'manual',
        ])->all();

        $historyFile = base_path('../data_snapshot/extracted/automatic_accrual_history.json');
        $historyData = is_file($historyFile) ? (json_decode(file_get_contents($historyFile), true, 512, JSON_THROW_ON_ERROR)['periods'] ?? []) : [];
        $dbHistory = AccrualHistory::whereIn('period', $periods)->get()->keyBy('period');
        $system = SystemConfig::get('system', []);
        $activePeriod = is_array($system) && !empty($system['periodYear']) && !empty($system['periodMonth'])
            ? sprintf('%04d-%02d', (int) $system['periodYear'], (int) $system['periodMonth'])
            : null;
        $liveAccruals = $activePeriod !== null && in_array($activePeriod, $periods, true)
            ? \Illuminate\Support\Facades\DB::table('db_accrual_items')->where('period', $activePeriod)->get()
            : collect();
        foreach ($liveAccruals as $row) $entries[] = [
            'period'=>$row->period,'account_code'=>$row->account_code,'name'=>$row->name,
            'category'=>$row->category,'amount_usd'=>$row->amount_usd,'entity'=>$row->entity,'source'=>'live_sheet',
        ];
        $hasLiveAccruals = $liveAccruals->isNotEmpty();
        if ($hasLiveAccruals) {
            $metrics = \Illuminate\Support\Facades\DB::table('monthly_metrics')->where('period', $activePeriod)->pluck('value', 'metric_key');
            $portQty = $metrics->get('portLoading');
            if ($portQty !== null && (float) $portQty >= 0) $entries[] = [
                'period'=>$activePeriod,'account_code'=>'5000104','name'=>'Barge Loading Conveyor Rent',
                'category'=>'BP PORT','amount_usd'=>max((float) $portQty * 1.332, 15000),'source'=>'live_metric',
            ];
            $feedQty = $metrics->get('washFeed');
            if ($feedQty !== null && (float) $feedQty > 0) $entries[] = [
                'period'=>$activePeriod,'account_code'=>'5000105','name'=>'Wash Plant Rent',
                'category'=>'Washing Plant','amount_usd'=>(float) $feedQty * 13.487,'source'=>'live_metric',
            ];
            foreach ([
                ['haulingBuaPort','Coal Hauling BUA to Port'],
                ['haulingWpPort','Coal Hauling WP to Port'],
            ] as [$metricKey, $category]) {
                $qty = $metrics->get($metricKey);
                if ($qty !== null && (float) $qty > 0) $entries[] = [
                    'period'=>$activePeriod,'account_code'=>'5000109','name'=>'Land And Road Rent',
                    'category'=>$category,'amount_usd'=>(float) $qty * 0.7,'source'=>'live_metric',
                ];
            }
        }
        if ($hasLiveAccruals && count(array_filter($periods, static fn (string $month): bool => $month !== $activePeriod)) === 0) {
            return $this->excludeAccrualsAlreadyPostedToD365($entries);
        }
        foreach ($periods as $month) {
            // The selected, synced month is recalculated from the current Sheets DB rows.
            // Prior months can use saved DB payloads. JSON snapshots are limited
            // to periods before the live-data cutover (October 2026).
            if ($month === $activePeriod && $hasLiveAccruals) continue;
            $savedHistory = $dbHistory->get($month)?->payload;
            $snapshot = $savedHistory ?? ($month < '2026-10' ? ($historyData[$month] ?? []) : []);
            foreach ((array) $snapshot as $row) $entries[] = $row + [
                'period' => $month, 'account_code' => $row['code'] ?? null, 'amount_usd' => $row['amount'] ?? 0,
                'source' => $savedHistory !== null ? 'database_history' : 'historical_snapshot',
            ];
        }
        return $this->excludeAccrualsAlreadyPostedToD365($entries);
    }

    private function excludeAccrualsAlreadyPostedToD365(array $entries): array
    {
        // The GL voucher is authoritative once an automatic live accrual is
        // posted. Match each expense row once; cent-level rounding is allowed.
        $eligible = array_values(array_filter($entries, static fn (array $entry): bool =>
            (string) ($entry['period'] ?? '') >= '2026-10'
            && in_array($entry['source'] ?? '', ['live_sheet','live_metric','database_history'], true)
            && trim((string) ($entry['account_code'] ?? $entry['code'] ?? '')) !== ''
            && (float) ($entry['amount_usd'] ?? $entry['amount'] ?? 0) != 0.0
        ));
        if (!$eligible) return $entries;

        $periods = array_values(array_unique(array_column($eligible, 'period')));
        $accounts = array_values(array_unique(array_map(static fn (array $entry): string => trim((string) ($entry['account_code'] ?? $entry['code'])), $eligible)));
        $posted = DB::table('gl_entries')->whereIn('period', $periods)->where('source_type', 'd365')
            ->where('voucher', 'like', 'ACCR%')->whereIn('main_account', $accounts)
            ->get(['period','main_account','amount_reporting']);
        if ($posted->isEmpty()) return $entries;

        $candidates = [];
        foreach ($posted as $row) $candidates[(string) $row->period.'|'.trim((string) $row->main_account)][] = [
            'amount'=>(float) $row->amount_reporting,'used'=>false,
        ];
        $excluded = [];
        foreach ($eligible as $entry) {
            $key = (string) $entry['period'].'|'.trim((string) ($entry['account_code'] ?? $entry['code']));
            $amount = (float) ($entry['amount_usd'] ?? $entry['amount'] ?? 0);
            foreach ($candidates[$key] ?? [] as $index => $candidate) {
                if ($candidate['used'] || abs($candidate['amount'] - $amount) > 0.02) continue;
                $candidates[$key][$index]['used'] = true;
                $excluded[serialize($entry)] = ($excluded[serialize($entry)] ?? 0) + 1;
                break;
            }
        }

        return array_values(array_filter($entries, static function (array $entry) use (&$excluded): bool {
            $key = serialize($entry);
            if (($excluded[$key] ?? 0) > 0) {
                $excluded[$key]--;
                return false;
            }
            return true;
        }));
    }

    public function financialAdjustmentRows(array $periods): array
    {
        $closed = array_fill_keys($this->closedPeriods(), true);
        $rows = [];
        foreach ($this->accrualEntries($periods) as $entry) {
            $month = (string) ($entry['period'] ?? '');
            $code = trim((string) ($entry['account_code'] ?? $entry['code'] ?? ''));
            $amount = (float) ($entry['amount_usd'] ?? $entry['amount'] ?? 0);
            if ($month === '' || $code === '' || $amount == 0 || isset($closed[$month])) continue;
            $rows[] = [
                'period'=>$month,'main_account'=>$code,'account_name'=>$entry['name'] ?? $entry['description'] ?? '',
                'amount_reporting'=>$amount,'costing_to'=>$entry['category'] ?? $entry['costing_to'] ?? null,
                'is_accrual_liability'=>false,
            ];
            $rows[] = [
                'period'=>$month,'main_account'=>'1530003','account_name'=>'Accrued Expense',
                'amount_reporting'=>-$amount,'costing_to'=>null,'is_accrual_liability'=>true,
            ];
        }
        return $rows;
    }

    private function sourceFilePeriods(string $relativePath, array $periods, array $monthsWithGl): array
    {
        $eligible = array_values(array_filter($periods, static fn (string $month): bool => $month < '2026-10' && !isset($monthsWithGl[$month])));
        if (!$eligible) return [];
        $path = base_path($relativePath);
        if (!is_file($path)) return [];
        $source = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['periods'] ?? [];
        return array_values(array_filter($eligible, static fn (string $month): bool => !empty($source[$month])));
    }

    private function sourceFingerprint(array $periods): string
    {
        $stamp = static function (string $table, array $wherePeriods = []) {
            $query = DB::table($table);
            if ($wherePeriods) $query->whereIn('period', $wherePeriods);
            return $query->selectRaw('COUNT(*) as row_count, MAX(updated_at) as latest_update')->first();
        };
        $files = [
            '../data_snapshot/extracted/cost_report_history.json',
            '../data_snapshot/extracted/automatic_accrual_history.json',
            '../data_snapshot/extracted/mining_capitalization_history.json',
            '../data_snapshot/extracted/cost_report_adjustments.json',
            '../data_snapshot/extracted/report_cost_closing.json',
        ];
        $fileStamps = [];
        foreach ($files as $file) {
            $path = base_path($file);
            $fileStamps[$file] = is_file($path) ? filemtime($path) : null;
        }
        $payload = [
            'periods'=>$periods,
            'gl'=>$stamp('gl_entries', $periods),
            'accounts'=>$stamp('cost_accounts'),
            'allocations'=>$stamp('d365_allocation_mappings'),
            'manual_accruals'=>$stamp('accruals', $periods),
            'accrual_history'=>$stamp('accrual_history', $periods),
            'sheet_accruals'=>$stamp('db_accrual_items', $periods),
            'sheets_sync'=>SystemConfig::get('sheetsSync', []),
            'closing_overrides'=>SystemConfig::get('report_cost_closing_overrides', []),
            'locked_periods'=>SystemConfig::get('locked_periods', []),
            'files'=>$fileStamps,
        ];
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR));
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
        $overrides = SystemConfig::get('report_cost_closing_overrides', []);
        if (is_string($overrides)) $overrides = json_decode($overrides, true) ?? [];
        if (!is_array($overrides)) $overrides = [];
        foreach ($overrides as $period => $isClosed) {
            $referencePeriods[(string) $period] = (bool) $isClosed;
        }
        $configured = SystemConfig::query()->where('key', 'locked_periods')->first()?->value ?? [];
        if (is_string($configured)) $configured = json_decode($configured, true) ?? [];
        if (!is_array($configured)) $configured = [];
        $closed = array_keys(array_filter($referencePeriods, static fn ($isClosed): bool => (bool) $isClosed));
        return array_values(array_unique(array_merge($closed, array_map('strval', $configured))));
    }
}
