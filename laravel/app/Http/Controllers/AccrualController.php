<?php
namespace App\Http\Controllers;
use App\Models\Accrual;
use App\Models\AuditLog;
use Illuminate\Http\Request;
class AccrualController extends Controller
{
    public function store(Request $request)
    {
        $data = $request->validate(['period'=>['required','regex:/^\d{4}-(0[1-9]|1[0-2])$/'],'account_code'=>['required','string'],'description'=>['nullable','string'],'qty'=>['required','numeric'],'rate_usd'=>['required','numeric'],'category'=>['nullable','string']]);
        $data['amount_usd'] = (float)$data['qty'] * (float)$data['rate_usd']; $data['source'] = 'manual';
        $accrual = Accrual::create($data); AuditLog::create(['user_id'=>$request->user()?->id,'action'=>'create','resource'=>'accrual','period'=>$data['period'],'payload'=>$accrual->toArray(),'ip_address'=>$request->ip()]);
        return response()->json(['success'=>true,'data'=>$accrual], 201);
    }
}
