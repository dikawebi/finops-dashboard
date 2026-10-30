<?php

namespace App\Http\Controllers;

use App\Models\MonthlyMetric;
use App\Models\DailyProduction;
use App\Models\CostVarianceRemark;
use App\Services\CostMatrixService;
use App\Services\FinancialStatementService;
use App\Services\InventoryValuationService;
use App\Services\CostPerMtService;
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
        $baselinePath = base_path("../baseline/reference_dump/production-summary_{$period}_{$mode}.json");
        if (is_file($baselinePath)) {
            $data = json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR)['data'] ?? [];
            return response()->json(['success' => true, 'data' => $data]);
        }
        $daily = DailyProduction::period($period)->orderBy('production_date')->get();
        return response()->json(['success' => true, 'data' => [
            'dbMetrics' => MonthlyMetric::period($period)->get()->keyBy('metric_key'),
            'dailyTrends' => $daily->map(fn (DailyProduction $row): array => [
                'date' => $row->production_date->format('Y-m-d'),
                'nettoMT' => (float) $row->coal_getting_mt,
                'count' => 0,
            ])->values(),
        ]]);
    }

    public function integration(): JsonResponse
    {
        return response()->json(['success' => true, 'data' => ['d365' => ['status' => 'online', 'mode' => 'local snapshot'], 'googleSheets' => ['status' => 'manual-sync', 'mode' => 'local snapshot'], 'dataFreeze' => '2026-10-30', 'database' => ['glEntries' => \App\Models\GlEntry::count(), 'monthlyMetrics' => \App\Models\MonthlyMetric::count()]]]);
    }

    public function costPerMt(Request $request, CostPerMtService $service): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
        return response()->json(['success' => true, 'data' => $service->build($period, $mode)]);
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

    public function variance(Request $request, CostMatrixService $service): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
        $previous = date('Y-m', strtotime($period . '-01 -1 month'));
        $current = $service->build($period, $mode);
        $prior = $service->build($previous, 'mtd');
        $variance = [];
        $remarks = CostVarianceRemark::period($period)->get()->keyBy('account_code');
        $priorRows = collect($prior['rows'])->keyBy('code');
        foreach ($current['rows'] as $row) {
            $old = $priorRows->get($row['code']);
            $base = (float) ($old['allCost'] ?? 0);
            $delta = (float) $row['allCost'] - $base;
            $cogpFields = ['costMining', 'costHaulingBUAToWP', 'costHaulingBUAToPort', 'costHaulingWPToPort', 'costWP', 'costPort'];
            $directFields = ['directGA', 'sellingExpense'];
            $sumFields = static fn (array $source, array $fields): float => array_sum(array_map(static fn (string $field): float => (float) ($source[$field] ?? 0), $fields));
            $currentCategories = ['cogp' => $sumFields($row, $cogpFields), 'direct' => $sumFields($row, $directFields)];
            $previousCategories = ['cogp' => $sumFields($old ?? [], $cogpFields), 'direct' => $sumFields($old ?? [], $directFields)];
            $variance[] = ['code' => $row['code'], 'name' => $row['name'], 'current' => (float) $row['allCost'], 'previous' => $base, 'change' => $delta, 'changePct' => $base == 0.0 ? null : $delta / abs($base) * 100, 'categories' => ['current' => $currentCategories, 'previous' => $previousCategories], 'remark' => $remarks->get($row['code'])?->remark, 'isParent' => $row['isParent']];
        }
        return response()->json(['success' => true, 'data' => ['period' => $period, 'previousPeriod' => $previous, 'current' => $current, 'previous' => $prior, 'rows' => $variance]]);
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
