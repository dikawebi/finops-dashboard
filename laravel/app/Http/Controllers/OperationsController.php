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
class OperationsController extends Controller
{
    public function accounts() { return response()->json(['success'=>true,'data'=>CostAccount::orderBy('account_code')->get()]); }
    public function accruals(Request $request) { $period=$request->query('period'); $q=Accrual::query(); if($period)$q->where('period',$period); return response()->json(['success'=>true,'data'=>$q->orderBy('period')->get()]); }
    public function config() { return response()->json(['success'=>true,'data'=>SystemConfig::query()->pluck('value','key')]); }
    public function glData(Request $request)
    {
        $period = (string) $request->query('period', '2026-10');
        abort_unless((bool) preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period), 422, 'Invalid period.');
        $rows = GlEntry::query()->where('period', $period)->orderBy('entry_date')->orderBy('id')->limit(500)->get([
            'id','entry_date','journal_number','voucher','main_account','account_name','description','valuation_to','costing_to','amount_reporting','currency'
        ]);
        return response()->json(['success'=>true,'data'=>['period'=>$period,'limit'=>500,'rows'=>$rows]]);
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
