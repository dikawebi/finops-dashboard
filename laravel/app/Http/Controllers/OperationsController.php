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
