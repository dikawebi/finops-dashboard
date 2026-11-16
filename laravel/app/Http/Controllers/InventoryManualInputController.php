<?php

namespace App\Http\Controllers;

use App\Models\InventoryManualInput;
use App\Services\InventoryValuationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class InventoryManualInputController extends Controller
{
    private const LOCATIONS = ['bua', 'nonWash', 'washed', 'highAsh', 'port', 'transitPort', 'kunz', 'transitKunz', 'transitBuaWp', 'transitBuaPort', 'transitWpPort'];

    public function index(Request $request): JsonResponse
    {
        $period = (string) $request->query('period', '2026-10');
        abort_unless((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), 422, 'Invalid period.');
        $query = InventoryManualInput::query();
        if ($request->query('mode') === 'ytd') $query->whereBetween('period', [substr($period, 0, 4).'-01', $period]);
        else $query->where('period', $period);
        $rows = $query->orderByDesc('id')->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function store(Request $request, InventoryValuationService $valuation): JsonResponse
    {
        $payload = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'input_type' => ['required', Rule::in(['sale', 'adjustment'])],
            'location' => ['required', Rule::in(self::LOCATIONS)],
            'reference' => ['required', 'string', 'max:100'],
            'qty_mt' => ['required', 'numeric'],
            'adjustment_usd' => ['nullable', 'numeric'],
            'sale_price_usd_per_mt' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:2000'],
        ]);
        $payload['reference'] = trim($payload['reference']);
        if ($payload['reference'] === '') return response()->json(['message' => 'Reference is required.'], 422);
        if ($payload['input_type'] === 'sale') {
            if (!in_array($payload['location'], ['port', 'transitPort', 'kunz', 'transitKunz'], true)) return response()->json(['message' => 'Sales can only be recorded at Port or Kunz.'], 422);
            if ((float) $payload['qty_mt'] <= 0 || !isset($payload['sale_price_usd_per_mt'])) return response()->json(['message' => 'Sales require positive quantity and a sale price.'], 422);
            $payload['adjustment_usd'] = 0;
        } elseif (abs((float) $payload['qty_mt']) < 0.000001 && abs((float) ($payload['adjustment_usd'] ?? 0)) < 0.000001) {
            return response()->json(['message' => 'Adjustment must change quantity or inventory value.'], 422);
        }
        $duplicate = InventoryManualInput::query()->where('period', $payload['period'])->where('input_type', $payload['input_type'])->where('location', $payload['location'])->where('reference', $payload['reference'])->exists();
        if ($duplicate) return response()->json(['message' => 'This reference already exists for the selected period, type, and location.'], 422);

        $current = $valuation->build($payload['period'], 'mtd');
        $stage = collect($current['stages'] ?? [])->firstWhere('id', $payload['location']);
        if (!$stage) return response()->json(['message' => 'The selected location is not available in the valuation result.'], 422);
        if ($payload['input_type'] === 'sale' && (float) $payload['qty_mt'] > (float) ($stage['endingQtyMT'] ?? 0) + 0.000001) {
            return response()->json(['message' => 'Sales quantity exceeds the available stock at this location.'], 422);
        }

        $record = DB::transaction(fn () => InventoryManualInput::create($payload + ['status' => 'active']));
        return response()->json(['success' => true, 'data' => $record], 201);
    }

    public function void(Request $request, int $id): JsonResponse
    {
        $payload = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $record = InventoryManualInput::query()->findOrFail($id);
        if ($record->status === 'voided') return response()->json(['success' => true, 'data' => $record]);
        $record->forceFill(['status' => 'voided', 'voided_at' => now(), 'void_reason' => $payload['reason']])->save();
        return response()->json(['success' => true, 'data' => $record]);
    }
}
