<?php

namespace App\Http\Controllers;

use App\Models\FinancialAccountMapping;
use App\Models\FinancialBaseline;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FinancialAccountMappingController extends Controller
{
    public function index(): JsonResponse
    {
        $baseline = FinancialBaseline::firstOrFail();
        $choices = [];
        foreach (($baseline->sections['profitLoss'] ?? []) as $line) {
            if (($line['kind'] ?? null) === 'detail') $choices[] = ['id'=>$line['id'], 'label'=>$line['label'], 'section'=>'Profit & Loss'];
        }
        foreach (($baseline->sections['balanceSheet'] ?? []) as $line) {
            if (($line['kind'] ?? null) === 'detail' || ($line['id'] ?? null) === 'totalEquity') {
                $choices[] = ['id'=>$line['id'], 'label'=>$line['label'], 'section'=>'Balance Sheet'];
            }
        }

        $accounts = FinancialAccountMapping::orderBy('account_code')->get()->mapWithKeys(static fn (FinancialAccountMapping $mapping): array => [
            (string) $mapping->account_code => [
                'name'=>$mapping->account_name,
                'rowId'=>$mapping->row_id,
                'sourceLabel'=>$mapping->source_label,
                'referenceSep'=>$mapping->reference_sep === null ? null : (float) $mapping->reference_sep,
                'referenceOct'=>$mapping->reference_oct === null ? null : (float) $mapping->reference_oct,
            ],
        ]);

        return response()->json(['success'=>true, 'data'=>['accounts'=>$accounts, 'choices'=>$choices]]);
    }

    public function update(Request $request, string $accountCode): JsonResponse
    {
        $baseline = FinancialBaseline::firstOrFail();
        $mapping = FinancialAccountMapping::where('account_code', $accountCode)->firstOrFail();
        $rowId = (string) $request->input('rowId', '');
        $code = (string) $mapping->account_code;
        $isProfitLossAccount = (bool) preg_match('/^[3-9]/', $code);
        $section = $isProfitLossAccount ? 'profitLoss' : 'balanceSheet';
        $valid = collect($baseline->sections[$section] ?? [])->contains(static fn (array $line): bool =>
            ($line['id'] ?? null) === $rowId && (($line['kind'] ?? null) === 'detail' || $line['id'] === 'totalEquity')
        );
        if (!$valid) {
            return response()->json(['success'=>false, 'error'=>'Choose a valid row in the account’s statement.'], 422);
        }

        $mapping->row_id = $rowId;
        $mapping->save();

        return response()->json(['success'=>true, 'data'=>['accountCode'=>$code, 'rowId'=>$rowId]]);
    }
}
