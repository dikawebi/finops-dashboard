<?php

namespace App\Http\Controllers;

use App\Models\MonthlyMetric;
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
        abort_unless((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), 422, 'Invalid period.');
        return response()->json(['success' => true, 'data' => $service->build($period)]);
    }

    public function production(Request $request): JsonResponse
    {
        $period = (string) $request->query('period', '2026-10');
        abort_unless((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), 422, 'Invalid period.');
        return response()->json(['success' => true, 'data' => MonthlyMetric::period($period)->get()]);
    }

    public function costPerMt(Request $request, CostPerMtService $service): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
        return response()->json(['success' => true, 'data' => $service->build($period, $mode)]);
    }

    public function variance(Request $request, CostMatrixService $service): JsonResponse
    {
        [$period, $mode] = $this->periodMode($request);
        $previous = date('Y-m', strtotime($period . '-01 -1 month'));
        $current = $service->build($period, $mode);
        $prior = $service->build($previous, 'mtd');
        $variance = [];
        $priorRows = collect($prior['rows'])->keyBy('code');
        foreach ($current['rows'] as $row) { $old = $priorRows->get($row['code']); $base = (float) ($old['allCost'] ?? 0); $delta = (float) $row['allCost'] - $base; $variance[] = ['code' => $row['code'], 'name' => $row['name'], 'current' => (float) $row['allCost'], 'previous' => $base, 'change' => $delta, 'changePct' => $base == 0.0 ? null : $delta / abs($base) * 100, 'isParent' => $row['isParent']]; }
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
