<?php

namespace App\Http\Controllers;

use App\Models\MonthlyMetric;
use App\Models\DailyProduction;
use App\Models\CostVarianceRemark;
use App\Models\DbCostSummary;
use App\Models\DbCostRemark;
use App\Models\GlEntry;
use App\Models\SystemConfig;
use App\Services\CostMatrixService;
use App\Services\FinancialStatementService;
use App\Services\InventoryValuationService;
use App\Services\CostPerMtService;
use App\Services\EquipmentCostService;
use App\Services\GoogleSheetsProductionSync;
use App\Services\D365\D365AllocationService;
use App\Services\DailyCogpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function costMatrix(Request $request, CostMatrixService $service): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
        $data = $service->build($period, $mode);
        if ($period === '2026-10' && !in_array('2026-10', $data['d365Periods'] ?? [], true)) {
            return response()->json(['success'=>false,'message'=>'October 2026 cost overview requires imported D365 GL data. Baseline data is not used for October.'], 409);
        }
        return response()->json(['success' => true, 'data' => $data]);
    }

    public function updateReportCostClosing(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'closed' => ['required', 'boolean'],
        ]);
        $overrides = SystemConfig::get('report_cost_closing_overrides', []);
        if (is_string($overrides)) $overrides = json_decode($overrides, true) ?? [];
        if (!is_array($overrides)) $overrides = [];
        $overrides[$payload['period']] = (bool) $payload['closed'];
        SystemConfig::put('report_cost_closing_overrides', $overrides);

        return response()->json(['success' => true, 'data' => [
            'period' => $payload['period'],
            'closed' => (bool) $payload['closed'],
        ]]);
    }

    public function financialStatements(Request $request, FinancialStatementService $service): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
        return response()->json(['success' => true, 'data' => $service->build($period, $mode)]);
    }

    public function inventory(Request $request, InventoryValuationService $service): JsonResponse
    {
        $period = (string) $request->query('period', '2026-10');
        $mode = (string) $request->query('mode', 'mtd');
        abort_unless((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), 422, 'Invalid period.');
        abort_unless(in_array($mode, ['mtd', 'ytd'], true), 422, 'Invalid mode.');
        if ($period === '2026-10') {
            $sheetsReady = \Illuminate\Support\Facades\DB::table('inventory_movements')->where('period', $period)->where('source', 'google_sheets')->exists()
                && DailyProduction::query()->where('period', $period)->where('source', 'google_sheets')->exists();
            $d365Ready = GlEntry::query()->where('period', $period)->where('source_type', 'd365')->exists();
            if (!$sheetsReady || !$d365Ready) return response()->json(['success'=>false,'message'=>'October inventory requires Google Sheets production sync and imported D365 GL. Baseline data is not used for October.'], 409);
        }
        return response()->json(['success' => true, 'data' => $service->build($period, $mode)]);
    }

    public function production(Request $request): JsonResponse
    {
        $period = (string) $request->query('period', '2026-10');
        $mode = (string) $request->query('mode', 'mtd');
        abort_unless((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), 422, 'Invalid period.');
        abort_unless(in_array($mode, ['mtd', 'ytd'], true), 422, 'Invalid mode.');
        $yearStart = substr($period, 0, 4).'-01';
        $syncedInPeriod = DailyProduction::query()->where('source', 'google_sheets')->where('period', $period)->exists();
        $syncedInYtd = DailyProduction::query()->where('source', 'google_sheets')->whereBetween('period', [$yearStart, $period])->exists();
        if ($period === '2026-10' && !$syncedInPeriod) {
            return response()->json(['success'=>false,'message'=>'October 2026 Executive Overview requires Google Sheets production sync. Baseline data is not used for October.'], 409);
        }
        if (($mode === 'mtd' && !$syncedInPeriod) || ($mode === 'ytd' && !$syncedInYtd)) {
            $baselinePath = base_path("../baseline/reference_dump/production-summary_{$period}_{$mode}.json");
            if (is_file($baselinePath)) {
                $data = json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
                return response()->json(['success' => true, 'data' => $data]);
            }
        }
        $daily = DailyProduction::query()->whereBetween('period', [$mode === 'ytd' ? $yearStart : $period, $period])
            ->where('source', 'google_sheets')->whereNotNull('coal_getting_mt')->where('coal_getting_mt', '>', 0)->orderBy('production_date')->get();
        $metrics = MonthlyMetric::period($period)->get()->keyBy('metric_key');
        if ($mode === 'ytd') {
            $metricRows = MonthlyMetric::query()->whereBetween('period', [$yearStart, $period])->orderBy('period')->get()->groupBy('metric_key');
            $metrics = $metricRows->map(function ($rows, string $key): object {
                $available = $rows->filter(fn ($row): bool => $row->value !== null);
                $missing = $rows->filter(fn ($row): bool => $row->value === null)->pluck('period')->values()->all();
                $sample = $rows->first();
                return (object) [
                    'metric_key'=>$key,'label'=>$sample->label ?? $key,'unit'=>$sample->unit,
                    'value'=>$available->isEmpty() ? null : (string) $available->sum(fn ($row): float => (float) $row->value),
                    'missingPeriods'=>$missing,
                ];
            });
        }
        $fromDate = $mode === 'ytd' ? $yearStart.'-01' : $period.'-01';
        $toDate = \Carbon\Carbon::createFromFormat('Y-m', $period)->endOfMonth()->toDateString();
        $activityFlows = \Illuminate\Support\Facades\DB::table('production_activity_details')
            ->select('activity','location','activity_type','route','contractor','equipment','metric','unit')
            ->selectRaw('SUM(value) as value')
            ->whereBetween('production_date', [$fromDate, $toDate])->where('source', 'google_sheets')
            ->whereNotNull('value')
            ->groupBy('activity','location','activity_type','route','contractor','equipment','metric','unit')
            ->orderBy('activity')->orderBy('location')->orderBy('route')->get();
        $activityMonths = \Illuminate\Support\Facades\DB::table('production_activity_details')
            ->whereBetween('production_date', [$fromDate, $toDate])->where('source', 'google_sheets')
            ->selectRaw("DATE_FORMAT(production_date, '%Y-%m') as period")->distinct()->orderBy('period')->pluck('period')->all();
        return response()->json(['success' => true, 'data' => [
            'dbMetrics' => $metrics,
            'activityFlows' => $activityFlows,
            'activityDetailPeriods' => $activityMonths,
            'productionSourcePeriods' => $daily->pluck('period')->unique()->values(),
            'periodRange' => ['from'=>$fromDate, 'to'=>$toDate],
            'sheetLastSyncedAt' => \App\Models\SystemConfig::get('sheetsSync', [])['last_synced_at'] ?? null,
            'dataSource' => ($mode === 'ytd' ? $syncedInYtd : $syncedInPeriod) ? 'Google Sheets sync' : 'database',
            'dailyTrends' => $daily->map(fn (DailyProduction $row): array => [
                'date' => $row->production_date->format('Y-m-d'),
                'nettoMT' => (float) $row->coal_getting_mt,
                'count' => 0,
            ])->values(),
        ]]);
    }

    public function overviewDaily(Request $request, D365AllocationService $allocation, DailyCogpService $dailyCogp): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
        if ($period === '2026-10' && !DailyProduction::query()->where('period', $period)->where('source', 'google_sheets')->exists()) {
            return response()->json(['success'=>false,'message'=>'October daily production requires Google Sheets sync.'], 409);
        }
        $fromPeriod = $mode === 'ytd' ? substr($period, 0, 4).'-01' : $period;
        $fromDate = $fromPeriod.'-01';
        $toDate = \Carbon\Carbon::createFromFormat('Y-m', $period)->endOfMonth()->toDateString();
        $daily = DailyProduction::query()->whereBetween('period', [$fromPeriod, $period])->where('source', 'google_sheets')
            ->whereNotNull('coal_getting_mt')->orderBy('production_date')->get(['production_date','coal_getting_mt']);
        $activities = \Illuminate\Support\Facades\DB::table('production_activity_details')->whereBetween('production_date', [$fromDate, $toDate])
            ->where('source', 'google_sheets')->whereNotNull('value')->get(['production_date','activity','activity_type','contractor','metric','value']);
        $dailyMap = [];
        foreach ($daily as $row) {
            $date = $row->production_date->format('Y-m-d');
            $dailyMap[$date] = ['date'=>$date,'coal'=>(float) $row->coal_getting_mt,'ob'=>null,'hauling'=>null];
        }
        foreach ($activities as $row) {
            $date = substr((string) $row->production_date, 0, 10);
            $dailyMap[$date] ??= ['date'=>$date,'coal'=>null,'ob'=>null,'hauling'=>null];
            if (strcasecmp((string) $row->activity, 'OB Removal') === 0) $dailyMap[$date]['ob'] = (float) ($dailyMap[$date]['ob'] ?? 0) + (float) $row->value;
            if (strcasecmp((string) $row->activity, 'Coal Hauling') === 0 && strcasecmp((string) $row->metric, 'quantity') === 0) $dailyMap[$date]['hauling'] = (float) ($dailyMap[$date]['hauling'] ?? 0) + (float) $row->value;
        }

        $glRows = GlEntry::query()->where('source_type', 'd365')->whereBetween('period', [$fromPeriod, $period])
            ->whereBetween('entry_date', [$fromDate, $toDate])->get(['entry_date','main_account','amount_reporting','costing_to','valuation_to','activity','location','department','group_activity']);
        $costs = [];
        $mappingCache = [];
        foreach ($glRows as $entry) {
            $date = $entry->entry_date?->format('Y-m-d');
            if (!$date) continue;
            $code = (string) $entry->main_account;
            if (!preg_match('/^[5-9]/', $code)) continue;
            $key = implode('|', [(string)$entry->main_account,(string)$entry->activity,(string)$entry->location,(string)$entry->department,(string)$entry->group_activity]);
            $mapping = $mappingCache[$key] ??= $allocation->resolveGlEntry($entry);
            $costing = strtolower(trim((string) ($mapping['costing_to'] ?? $entry->costing_to)));
            $valuation = strtolower(preg_replace('/[\s_-]+/', '', trim((string) ($mapping['valuation_to'] ?? $entry->valuation_to))));
            $head = substr($code, 0, 1);
            $isDirect = preg_replace('/[\s_-]+/', '', $costing) === 'directexpense';
            $isExpense = in_array($head, ['7','8','9'], true) || preg_match('/^ga$|admin|overhead/', $costing) || ($isDirect && $valuation === 'notvaluated');
            $costs[$date] ??= ['date'=>$date,'ga'=>0.0];
            if ($isExpense) $costs[$date]['ga'] += (float) $entry->amount_reporting;
        }
        $accrualItems = \Illuminate\Support\Facades\DB::table('db_accrual_items')->whereBetween('period', [$fromPeriod, $period])->get();
        $cogpParameters = \Illuminate\Support\Facades\DB::table('db_daily_cogp_parameters')->whereBetween('period', [$fromPeriod, $period])->get();
        $calculatedCogp = $dailyCogp->calculate($daily, $activities, $accrualItems, $cogpParameters);
        $costDates = array_values(array_unique([...array_keys($costs), ...array_keys($calculatedCogp)]));
        sort($costDates);
        $costSeries = array_map(static fn (string $date): array => [
            'date'=>$date,
            'cogp'=>$calculatedCogp[$date]['cogp'] ?? null,
            'operation'=>$calculatedCogp[$date]['operation'] ?? null,
            'fuel'=>$calculatedCogp[$date]['fuel'] ?? null,
            'cogpAvailable'=>isset($calculatedCogp[$date]) && $calculatedCogp[$date]['cogp'] !== null,
            'ga'=>$costs[$date]['ga'] ?? 0.0,
            'gaAvailable'=>isset($costs[$date]),
        ], $costDates);
        $haulingEntities = \Illuminate\Support\Facades\DB::table('db_accrual_items')
            ->whereBetween('period', [$fromPeriod, $period])
            ->whereIn('account_code', ['5000407', '5000408', '5000409'])
            ->get(['period', 'account_code', 'entity', 'qty']);
        return response()->json(['success'=>true,'data'=>[
            'haulingEntities'=>$haulingEntities,
            'daily'=>array_values($dailyMap),'costs'=>$costSeries,
            'source'=>'Synced Google Sheets production + DB accrual inputs for COGP; D365 dated GL for GA','mode'=>$mode,'period'=>$period,
            'sheetsLastSyncedAt'=>SystemConfig::get('sheetsSync', [])['last_synced_at'] ?? null,
        ]]);
    }

    public function overviewAllocation(Request $request, D365AllocationService $allocation): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
        $group = (string) $request->query('group', 'account');
        abort_unless(in_array($group, ['location','activity','department'], true), 422, 'Invalid allocation grouping.');
        $from = $mode === 'ytd' ? substr($period, 0, 4).'-01' : $period;
        $rows = GlEntry::query()->where('source_type', 'd365')->whereBetween('period', [$from, $period])
            ->get(['main_account','amount_reporting','costing_to','valuation_to','activity','location','department','group_activity']);
        $totals = ['cogp'=>[],'expense'=>[],'all'=>[]];
        $mappingCache = [];
        foreach ($rows as $entry) {
            $code = (string) $entry->main_account;
            $key = implode('|', [(string)$entry->main_account,(string)$entry->activity,(string)$entry->location,(string)$entry->department,(string)$entry->group_activity]);
            $mapping = $mappingCache[$key] ??= $allocation->resolveGlEntry($entry);
            $costing = strtolower(trim((string) ($mapping['costing_to'] ?? $entry->costing_to)));
            $isDirect = preg_replace('/[\s_-]+/', '', $costing) === 'directexpense';
            $head = substr($code, 0, 1);
            $bucket = in_array($head, ['7','8','9'], true) || $isDirect || $costing === 'ga' || str_contains($costing, 'admin') || str_contains($costing, 'overhead') ? 'expense' : 'cogp';
            $dimension = trim((string) ($entry->{$group} ?? '')) ?: 'Unspecified';
            $amount = (float) $entry->amount_reporting;
            $totals[$bucket][$dimension] = ($totals[$bucket][$dimension] ?? 0) + $amount;
            $totals['all'][$dimension] = ($totals['all'][$dimension] ?? 0) + $amount;
        }
        return response()->json(['success'=>true,'data'=>['period'=>$period,'mode'=>$mode,'group'=>$group,'source'=>'D365 GL transactions','totals'=>$totals]]);
    }

    public function equipmentCost(Request $request, EquipmentCostService $service): JsonResponse
    {
        $period = (string) $request->query('period', '2026-10');
        $mode = (string) $request->query('mode', 'mtd');
        abort_unless((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), 422, 'Invalid period.');
        abort_unless(in_array($mode, ['mtd', 'ytd'], true), 422, 'Invalid mode.');
        return response()->json(['success'=>true,'data'=>$service->build($period, $mode)]);
    }

    public function integration(): JsonResponse
    {
        $d365 = config('services.d365');
        $d365Configured = (bool) ($d365['enabled'] ?? false) && filled($d365['environment_url'] ?? null) && filled($d365['tenant_id'] ?? null) && filled($d365['client_id'] ?? null) && filled($d365['client_secret'] ?? null);
        $sheets = config('services.google_sheets');
        $sheetsConfigured = (bool) ($sheets['enabled'] ?? false) && filled($sheets['spreadsheet_id'] ?? null) && filled($sheets['credentials_path'] ?? null) && is_file($sheets['credentials_path']);
        $serviceAccountEmail = null;
        if ($sheetsConfigured) {
            try { $serviceAccountEmail = json_decode(file_get_contents($sheets['credentials_path']), true, 512, JSON_THROW_ON_ERROR)['client_email'] ?? null; } catch (\Throwable) { $sheetsConfigured = false; }
        }
        $sync = \App\Models\SystemConfig::get('sheetsSync', []);
        return response()->json(['success' => true, 'data' => ['d365' => ['status' => $d365Configured ? 'configured' : 'not-configured', 'mode' => $d365Configured ? 'environment config' : 'local snapshot', 'environmentUrl' => $d365['environment_url'] ?? null, 'entityName' => $d365['entity_name'] ?? null, 'autoSync' => (bool) ($d365['auto_sync'] ?? false)], 'googleSheets' => ['status' => $sheetsConfigured ? 'configured' : 'not-configured', 'mode' => 'Google Sheets API', 'spreadsheetId' => $sheets['spreadsheet_id'] ?? null, 'sheetName' => $sheets['sheet_name'] ?? 'Produksi', 'serviceAccountEmail' => $serviceAccountEmail, 'credentialStatus' => $sheetsConfigured ? 'Private key loaded on server' : 'Credential unavailable', 'lastSyncedAt' => $sync['last_synced_at'] ?? null, 'lastRows' => $sync['rows'] ?? null], 'dataFreeze' => '2026-10-30', 'database' => ['glEntries' => \App\Models\GlEntry::count(), 'monthlyMetrics' => \App\Models\MonthlyMetric::count()]]]);
    }

    public function syncGoogleSheets(GoogleSheetsProductionSync $sync): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $sync->sync()]);
    }

    public function previewGoogleSheets(GoogleSheetsProductionSync $sync): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $sync->preview()]);
    }

    public function costPerMt(Request $request, CostPerMtService $service): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
        return response()->json(['success' => true, 'data' => $service->build($period, $mode)]);
    }

    public function excelCostExample(): JsonResponse
    {
        $path = base_path('../baseline/reference_dump/excel-cost-example.static.json');
        if (!is_file($path)) {
            return response()->json(['success' => true, 'data' => ['abcMonthly' => null, 'cogpMonthly' => null]]);
        }

        $data = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
        return response()->json(['success' => true, 'data' => [
            'abcMonthly' => $data['abcMonthly'] ?? null,
            'cogpMonthly' => $data['cogpMonthly'] ?? null,
        ]]);
    }

    public function dbCostSummary(Request $request, CostMatrixService $costs): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
        $sync = SystemConfig::get('sheetsSync', []);
        $syncedPeriods = $sync['db_cost_periods'] ?? [];
        if (in_array($period, $syncedPeriods, true)) {
            // This endpoint also feeds the month-range table, so return every
            // synchronized month through the selected period for both modes.
            $periods = array_values(array_filter($syncedPeriods, static fn (string $month): bool =>
                str_starts_with($month, substr($period, 0, 4).'-') && $month <= $period
            ));
            sort($periods);
            if (!$periods) $periods = [$period];
            $records = DbCostSummary::query()->whereIn('period', $periods)->get();
            $groups = [];
            foreach ($records as $record) {
                $key = json_encode([$record->entity, $record->activity], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (!isset($groups[$key])) $groups[$key] = ['entity'=>$record->entity,'activity'=>$record->activity,'months'=>[]];
                $groups[$key]['months'][$record->period] = (float) ($groups[$key]['months'][$record->period] ?? 0) + (float) $record->amount;
            }
            $glPeriods = GlEntry::query()->whereIn('period', $periods)->where('period', '>=', '2026-10')->distinct()->pluck('period')->all();
            foreach ($glPeriods as $glPeriod) {
                $grand = $costs->build($glPeriod, 'mtd')['grandTotal'];
                foreach ([['Overhead BUA', (float) $grand['valMining'], false], ['OVH JKT & PC', (float) $grand['directGA'], false], ['Washplant', (float) $grand['valWP'], true], ['Loading BLC', (float) $grand['valPort'], true]] as [$activity, $amount, $add]) {
                    foreach ($groups as &$group) {
                        if ($group['entity'] !== 'BP' || $group['activity'] !== $activity) continue;
                        $existing = (float) ($group['months'][$glPeriod] ?? 0);
                        $group['months'][$glPeriod] = $add ? $existing + $amount : $amount;
                        break;
                    }
                    unset($group);
                }
            }
            $remarks = DbCostRemark::query()->where('period', $period)->get()->keyBy('entity_activity_key');
            $remarkValues = [];
            $rows = [];
            foreach ($groups as $key => $group) {
                $amount = $mode === 'ytd'
                    ? array_sum(array_map('floatval', $group['months']))
                    : (float) ($group['months'][$period] ?? 0);
                $id = json_encode(['entity', $group['entity'], $group['activity']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $remark = $remarks->get($id)?->remark;
                if ($remark !== null) $remarkValues[$id] = $remark;
                $rows[] = ['id'=>$id,'entity'=>$group['entity'],'activity'=>$group['activity'],'component'=>1,'amount'=>$amount,'months'=>$group['months']];
            }
            $rowPeriodsWithGl = array_values($glPeriods);
            return response()->json(['success'=>true,'data'=>[
                'period'=>$period,'mode'=>$mode,'dataSource'=>'Google Sheets DB sync','rows'=>$rows,
                'remarks'=>$remarkValues,'glOverheadPeriods'=>$rowPeriodsWithGl,'syncedPeriods'=>$syncedPeriods,
            ]]);
        }

        $path = base_path("../baseline/reference_dump/db-cost-summary_{$period}_{$mode}.json");
        if (!is_file($path)) {
            return response()->json(['success' => true, 'data' => ['period' => $period, 'mode' => $mode, 'dataSource' => 'baseline', 'rows' => [], 'remarks' => []]]);
        }

        $payload = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
        $rows = array_map(function (array $row) use ($period, $mode): array {
            $months = $row['months'] ?? [];
            if ($mode === 'ytd') {
                $amount = array_sum(array_map('floatval', array_filter($months, static fn ($value, $month): bool => $month <= $period, ARRAY_FILTER_USE_BOTH)));
            } else {
                $amount = (float) ($months[$period] ?? 0);
            }
            return [
                'id' => $row['id'] ?? null,
                'entity' => $row['entity'] ?? null,
                'activity' => $row['activity'] ?? null,
                'component' => $row['component'] ?? null,
                'amount' => $amount,
                'months' => $months,
            ];
        }, $payload['rows'] ?? []);

        return response()->json(['success' => true, 'data' => [
            'period' => $period,
            'mode' => $mode,
            'dataSource' => 'db-cost-summary baseline',
            'rows' => $rows,
            'remarks' => $payload['remarks'] ?? [],
            'glOverheadPeriods' => $payload['glOverheadPeriods'] ?? [],
        ]]);
    }

    public function saveDbCostRemark(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'id' => ['required', 'string', 'max:500'],
            'period' => ['required', 'date_format:Y-m'],
            'remark' => ['required', 'string', 'max:2000'],
        ]);
        DbCostRemark::updateOrCreate(
            ['period'=>$validated['period'], 'entity_activity_key'=>$validated['id']],
            ['remark'=>$validated['remark']]
        );
        return response()->json(['success' => true, 'data' => $validated]);
    }

    public function variance(Request $request, CostMatrixService $service): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
        $previous = date('Y-m', strtotime($period . '-01 -1 month'));
        // Variance is a month-to-month report. The global MTD/YTD toggle must
        // not turn the current side into YTD while the previous side stays MTD.
        $current = $service->build($period, 'mtd');
        $prior = $service->build($previous, 'mtd');
        $variance = [];
        $location = trim((string) $request->query('location', 'allCost'));
        $currentRows = collect($current['rows'])->keyBy('code');
        $priorRows = collect($prior['rows'])->keyBy('code');
        $hasCurrent = ($current['dataSource'] ?? 'none') !== 'none' || !empty($current['accrualPeriods']);
        $hasPrevious = ($prior['dataSource'] ?? 'none') !== 'none' || !empty($prior['accrualPeriods']);
        $varianceFields = ['allCost', 'costMining', 'costHaulingBUAToWP', 'costHaulingBUAToPort', 'costHaulingWPToPort', 'costWP', 'costPort', 'valMining', 'valHauling', 'valWP', 'valPort', 'directGA', 'sellingExpense'];
        abort_unless(in_array($location, $varianceFields, true), 422, 'Invalid variance category.');
        $remarks = CostVarianceRemark::period($period)->where('category', $location)->get()->keyBy('account_code');
        $cogpFields = ['costMining', 'costHaulingBUAToWP', 'costHaulingBUAToPort', 'costHaulingWPToPort', 'costWP', 'costPort'];
        // Valuation allocations are Direct Expense columns. directGA already
        // includes Not Valuated, so do not add valNotValuated a second time.
        $directFields = ['valMining', 'valHauling', 'valWP', 'valPort', 'directGA', 'sellingExpense'];
        $sumFields = static fn (array $source, array $fields): float => array_sum(array_map(static fn (string $field): float => (float) ($source[$field] ?? 0), $fields));
        foreach ($currentRows->keys()->merge($priorRows->keys())->unique() as $code) {
            $row = $currentRows->get($code);
            $old = $priorRows->get($code);
            $sourceRow = $row ?? $old;
            $base = $hasPrevious ? (float) ($old[$location] ?? 0) : null;
            $currentValue = $hasCurrent ? (float) ($row[$location] ?? 0) : null;
            $delta = $base !== null && $currentValue !== null ? $currentValue - $base : null;
            $variance[] = ['code' => $code, 'name' => $sourceRow['name'], 'current' => $currentValue, 'previous' => $base, 'change' => $delta, 'changePct' => $delta === null || $base == 0.0 ? null : $delta / abs($base) * 100,
                'categories' => ['current'=>['cogp'=>$hasCurrent ? $sumFields($row ?? [], $cogpFields) : null,'direct'=>$hasCurrent ? $sumFields($row ?? [], $directFields) : null], 'previous'=>['cogp'=>$hasPrevious ? $sumFields($old ?? [], $cogpFields) : null,'direct'=>$hasPrevious ? $sumFields($old ?? [], $directFields) : null]],
                'remark' => $remarks->get($code)?->remark, 'isParent' => $sourceRow['isParent']];
        }
        $locationOptions = $varianceFields;
        return response()->json(['success' => true, 'data' => ['period' => $period, 'previousPeriod' => $previous, 'current' => $current, 'previous' => $prior, 'hasCurrent'=>$hasCurrent, 'hasPrevious'=>$hasPrevious, 'rows' => $variance, 'locations' => $locationOptions]]);
    }

    public function saveVarianceRemark(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => ['required', 'date_format:Y-m'],
            'category' => ['required', 'in:allCost,costMining,costHaulingBUAToWP,costHaulingBUAToPort,costHaulingWPToPort,costWP,costPort,valMining,valHauling,valWP,valPort,directGA,sellingExpense'],
            'account_code' => ['required', 'string', 'max:20'],
            'remark' => ['nullable', 'string', 'max:2000'],
        ]);
        $remark = CostVarianceRemark::updateOrCreate(
            ['period' => $validated['period'], 'category' => $validated['category'], 'account_code' => $validated['account_code']],
            ['remark' => $validated['remark'] ?? '']
        );
        return response()->json(['success' => true, 'data' => ['id' => $remark->id, 'updated_at' => $remark->updated_at]]);
    }

    private function periodMode(Request $request): array
    {
        $period = (string) $request->query('period', '2026-10');
        $mode = (string) $request->query('mode', 'mtd');
        abort_unless((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), 422, 'Invalid period.');
        abort_unless(in_array($mode, ['mtd', 'ytd'], true), 422, 'Invalid mode.');
        return [$period, $mode];
    }
}
