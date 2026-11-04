<?php
namespace App\Http\Controllers;
use App\Models\AuditLog;
use App\Models\Accrual;
use App\Models\CostAccount;
use App\Models\GlEntry;
use App\Models\SystemConfig;
use App\Services\GlImportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
class OperationsController extends Controller
{
    public function accounts() { return response()->json(['success'=>true,'data'=>CostAccount::orderBy('account_code')->get()]); }
    public function accruals(Request $request) { $period=$request->query('period'); $q=Accrual::query(); if($period)$q->where('period',$period); return response()->json(['success'=>true,'data'=>$q->orderBy('period')->get()]); }
    public function config()
    {
        $configs = SystemConfig::query()->pluck('value', 'key')->except(['d365Config']);
        return response()->json(['success'=>true,'data'=>$configs]);
    }
    public function updateConfig(Request $request)
    {
        $payload = $request->validate([
            'key' => ['required', 'string', 'in:system,d365Config,sheetsConfig,inventorySetup'],
            'field' => ['required', 'string', 'max:80'],
            'value' => ['present'],
        ]);
        $editable = [
            'system' => ['companyName', 'currency', 'currencyUnit', 'periodYear', 'periodMonth', 'amountScale'],
            'd365Config' => ['enabled', 'entityName', 'syncIntervalMinutes', 'autoSync'],
            'sheetsConfig' => ['enabled', 'spreadsheetId', 'sheetName', 'range', 'syncIntervalMinutes', 'autoSync', 'gid'],
            'inventorySetup' => ['valuationMethod'],
        ];
        abort_unless(in_array($payload['field'], $editable[$payload['key']], true), 422, 'This configuration field is read-only.');
        $config = SystemConfig::get($payload['key'], []);
        if (!is_array($config)) $config = [];
        $value = $payload['value'];
        if (in_array($payload['field'], ['enabled', 'autoSync'], true)) $value = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        if (in_array($payload['field'], ['periodYear', 'periodMonth', 'amountScale', 'syncIntervalMinutes'], true)) $value = is_numeric($value) ? (int) $value : 0;
        $config[$payload['field']] = $value;
        SystemConfig::put($payload['key'], $config);
        return response()->json(['success'=>true,'data'=>[$payload['key'] => $config]]);
    }
    public function glData(Request $request)
    {
        $period = (string) $request->query('period', '2026-10');
        abort_unless((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), 422, 'Invalid period.');
        $perPage = min(max((int) $request->query('per_page', 100), 1), 500);
        $page = max((int) $request->query('page', 1), 1);
        $paginator = GlEntry::query()
            ->where('period', $period)
            ->orderBy('entry_date')
            ->orderBy('id')
            ->paginate($perPage, ['*'], 'page', $page);
        $rows = collect($paginator->items());
        $accountKeys = $rows->pluck('main_account')->filter()->map(fn ($value): string => (string) $value)->unique()->values();
        $mainAccounts = DB::table('d365_typed_records')
            ->where('entity_key', 'main_accounts')
            ->whereIn('source_key', $accountKeys->all())
            ->get(['source_key', 'account_code', 'account_name'])
            ->keyBy(fn ($row): string => (string) $row->source_key);
        $rows = $rows->map(function ($row) use ($mainAccounts) {
            $sourceKey = (string) ($row->main_account ?? '');
            $lookup = $mainAccounts->get($sourceKey);
            $row->main_account_source_key = $sourceKey ?: null;
            $row->main_account = $lookup?->account_code ?: $row->main_account;
            $row->main_account_lookup_name = $lookup?->account_name;
            return $row;
        });
        $columns = Schema::getColumnListing('gl_entries');
        $mainAccountPosition = array_search('main_account', $columns, true);
        if ($mainAccountPosition !== false) {
            array_splice($columns, $mainAccountPosition + 1, 0, ['main_account_lookup_name']);
        } else {
            $columns[] = 'main_account_lookup_name';
        }
        $columns[] = 'main_account_source_key';
        return response()->json(['success'=>true,'data'=>[
            'period'=>$period,
            'columns'=>$columns,
            'rows'=>$rows->values(),
            'pagination'=>[
                'current_page'=>$paginator->currentPage(),
                'last_page'=>$paginator->lastPage(),
                'per_page'=>$paginator->perPage(),
                'total'=>$paginator->total(),
            ],
        ]]);
    }

    public function glTransactionReview(Request $request): array
    {
        $period = (string) $request->query('period', '2026-10');
        abort_unless((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), 422, 'Invalid period.');
        $requestedPerPage = (string) $request->query('per_page', '100');
        $query = GlEntry::query()->where('source_type', 'd365')->where('period', $period);
        $this->applyGlReviewFilters($query, $request);
        $summaryQuery = clone $query;
        $summary = [
            'transactions' => (clone $summaryQuery)->count(),
            'amount_reporting' => (float) (clone $summaryQuery)->sum('amount_reporting'),
            'mapped' => (clone $summaryQuery)->where('mapping_status', 'mapped')->count(),
            'unmapped' => (clone $summaryQuery)->where(function ($builder): void { $builder->where('mapping_status', 'unmapped')->orWhereNull('mapping_status'); })->count(),
            'voucher_summary' => (clone $summaryQuery)->whereNotNull('voucher')->where('voucher', '<>', '')
                ->selectRaw("substr(voucher, 1, 3) as code, count(*) as transactions, sum(amount_reporting) as amount_reporting")
                ->groupBy('code')->orderBy('code')->get(),
        ];
        $totalRows = (int) $summary['transactions'];
        if ($requestedPerPage === 'all') @ini_set('memory_limit', '512M');
        $perPage = $requestedPerPage === 'all' ? max($totalRows, 1) : min(max((int) $requestedPerPage, 1), 2000);
        $query->select(['id', 'entry_date', 'main_account', 'account_name', 'voucher', 'journal_number', 'description', 'costing_to', 'valuation_to', 'currency', 'amount_idr', 'amount_reporting', 'source_key', 'mapping_status', 'year_closed', 'fuel_quantity', 'amount_txn', 'amount', 'posting_type', 'posting_layer', 'vendor_account', 'customer_account', 'invoice_no', 'activity', 'cash_flow', 'department', 'group_activity', 'location', 'project', 'project_name', 'cost_center', 'po_dt_number', 'gjae_id', 'created_datetime']);
        $paginator = $query->orderByDesc('entry_date')->orderByDesc('id')->paginate($perPage)->withQueryString();
        return [
            'period' => $period,
            'summary' => $summary,
            'rows' => $paginator->items(),
            'pagination' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()],
        ];
    }

    private function applyGlReviewFilters($query, Request $request): void
    {
        if ($request->filled('date_from')) $query->whereDate('entry_date', '>=', $request->query('date_from'));
        if ($request->filled('date_to')) $query->whereDate('entry_date', '<=', $request->query('date_to'));
        if ($request->filled('account')) $query->where('main_account', 'like', '%'.$request->query('account').'%');
        foreach (['account_name', 'voucher', 'journal_number', 'description', 'costing_to', 'valuation_to', 'currency'] as $field) {
            if ($request->filled($field)) $query->where($field, 'like', '%'.$request->query($field).'%');
        }
        if ($request->filled('mapping_status') && $request->query('mapping_status') !== 'all') {
            if ($request->query('mapping_status') === 'unmapped') $query->where(function ($builder): void { $builder->where('mapping_status', 'unmapped')->orWhereNull('mapping_status'); });
            else $query->where('mapping_status', $request->query('mapping_status'));
        }
        foreach (['valuation_to', 'costing_to'] as $field) {
            if ($request->filled($field)) $query->where($field, 'like', '%'.$request->query($field).'%');
        }
        if ($request->filled('search')) {
            $search = '%'.$request->query('search').'%';
            $query->where(function ($builder) use ($search): void {
                foreach (['main_account', 'account_name', 'journal_number', 'voucher', 'description', 'source_key'] as $field) $builder->orWhere($field, 'like', $search);
            });
        }
    }
    public function uploadGl(Request $request, GlImportService $importer)
    {
        $data=$request->validate(['rows'=>'required|array|min:1','filename'=>'nullable|string','note'=>'nullable|string']);
        $batch=$importer->import($data['rows'],$data['filename']??'api-upload.json',$data['note']??null);
        AuditLog::create(['user_id'=>$request->user()?->id,'action'=>'upload','resource'=>'gl','payload'=>['batch_id'=>$batch->id,'rows'=>$batch->row_count],'ip_address'=>$request->ip()]);
        return response()->json(['success'=>true,'data'=>$batch],201);
    }
    public function deleteGl(Request $request)
    {
        $count=DB::transaction(fn()=>GlEntry::query()->delete());
        AuditLog::create(['user_id'=>$request->user()?->id,'action'=>'delete','resource'=>'gl','payload'=>['rows'=>$count],'ip_address'=>$request->ip()]);
        return response()->json(['success'=>true,'deleted'=>$count]);
    }
}
