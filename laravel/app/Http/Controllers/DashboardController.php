<?php

namespace App\Http\Controllers;

use App\Models\MonthlyMetric;
use App\Models\DailyProduction;
use App\Models\CostVarianceRemark;
use App\Models\GlEntry;
use App\Services\CostMatrixService;
use App\Services\FinancialStatementService;
use App\Services\InventoryValuationService;
use App\Services\CostPerMtService;
use App\Services\EquipmentCostService;
use App\Services\GoogleSheetsProductionSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function costMatrix(Request $request, CostMatrixService $service): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
        return response()->json(['success' => true, 'data' => $service->build($period, $mode)]);
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
        if (($mode === 'mtd' && !$syncedInPeriod) || ($mode === 'ytd' && !$syncedInYtd)) {
            $baselinePath = base_path("../baseline/reference_dump/production-summary_{$period}_{$mode}.json");
            if (is_file($baselinePath)) {
                $data = json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
                return response()->json(['success' => true, 'data' => $data]);
            }
        }
        $daily = DailyProduction::period($period)->orderBy('production_date')->get();
        $monthlyRows = MonthlyMetric::query()->where('metric_key', 'coalGetting')->where('period', '<=', $period)->where('period', '>=', $yearStart)->get();
        $metrics = MonthlyMetric::period($period)->get()->keyBy('metric_key');
        if ($mode === 'ytd' && $syncedInYtd && $monthlyRows->isNotEmpty()) {
            $metric = $metrics->get('coalGetting');
            $ytdValue = $monthlyRows->sum(fn ($row): float => (float) $row->value);
            if ($metric) $metric->value = (string) $ytdValue;
            else $metrics->put('coalGetting', (object) ['metric_key'=>'coalGetting','label'=>'Coal Getting','unit'=>'MT','value'=>(string)$ytdValue]);
        }
        return response()->json(['success' => true, 'data' => [
            'dbMetrics' => $metrics,
            'sheetLastSyncedAt' => \App\Models\SystemConfig::get('sheetsSync', [])['last_synced_at'] ?? null,
            'dataSource' => ($mode === 'ytd' ? $syncedInYtd : $syncedInPeriod) ? 'Google Sheets sync' : 'database',
            'dailyTrends' => $daily->map(fn (DailyProduction $row): array => [
                'date' => $row->production_date->format('Y-m-d'),
                'nettoMT' => (float) $row->coal_getting_mt,
                'count' => 0,
            ])->values(),
        ]]);
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

    public function dbCostSummary(Request $request): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
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
            'remark' => ['required', 'string', 'max:2000'],
        ]);
        return response()->json(['success' => true, 'data' => $validated]);
    }

    public function variance(Request $request, CostMatrixService $service): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
        $previous = date('Y-m', strtotime($period . '-01 -1 month'));
        $current = $service->build($period, $mode);
        $prior = $service->build($previous, 'mtd');
        $variance = [];
        $location = trim((string) $request->query('location', 'allCost'));
        $priorRows = collect($prior['rows'])->keyBy('code');
        $varianceFields = ['allCost', 'costMining', 'costHaulingBUAToWP', 'costHaulingBUAToPort', 'costHaulingWPToPort', 'costWP', 'costPort', 'valMining', 'valHauling', 'valWP', 'valPort', 'directGA', 'sellingExpense'];
        abort_unless(in_array($location, $varianceFields, true), 422, 'Invalid variance category.');
        $remarks = CostVarianceRemark::period($period)->where('category', $location)->get()->keyBy('account_code');
        foreach ($current['rows'] as $row) {
            $old = $priorRows->get($row['code']);
            $base = (float) ($old[$location] ?? 0);
            $currentValue = (float) ($row[$location] ?? 0);
            $delta = $currentValue - $base;
            $cogpFields = ['costMining', 'costHaulingBUAToWP', 'costHaulingBUAToPort', 'costHaulingWPToPort', 'costWP', 'costPort'];
            $directFields = ['directGA', 'sellingExpense'];
            $sumFields = static fn (array $source, array $fields): float => array_sum(array_map(static fn (string $field): float => (float) ($source[$field] ?? 0), $fields));
            $currentCategories = ['cogp' => $sumFields($row, $cogpFields), 'direct' => $sumFields($row, $directFields)];
            $previousCategories = ['cogp' => $sumFields($old ?? [], $cogpFields), 'direct' => $sumFields($old ?? [], $directFields)];
            $variance[] = ['code' => $row['code'], 'name' => $row['name'], 'current' => $currentValue, 'previous' => $base, 'change' => $delta, 'changePct' => $base == 0.0 ? null : $delta / abs($base) * 100, 'categories' => ['current' => $currentCategories, 'previous' => $previousCategories], 'remark' => $remarks->get($row['code'])?->remark, 'isParent' => $row['isParent']];
        }
        $locationOptions = $varianceFields;
        return response()->json(['success' => true, 'data' => ['period' => $period, 'previousPeriod' => $previous, 'current' => $current, 'previous' => $prior, 'rows' => $variance, 'locations' => $locationOptions]]);
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
