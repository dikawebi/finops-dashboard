<?php
namespace App\Http\Controllers;
use App\Models\Accrual;
use App\Models\AuditLog;
use App\Models\CostAccount;
use App\Models\SystemConfig;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
class AccrualController extends Controller
{
    private const CATEGORIES = ['Mining', 'Coal Hauling BUA to WP', 'Coal Hauling BUA to Port', 'Coal Hauling WP to Port', 'Washing Plant', 'BP PORT', 'GA', 'Selling Expense'];

    public function store(Request $request)
    {
        $data = $request->validate([
            'period' => ['required', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'account_code' => ['required', 'string', 'exists:cost_accounts,account_code'],
            'description' => ['nullable', 'string', 'max:255'],
            'qty' => ['required', 'numeric', 'min:0'],
            'rate_usd' => ['required', 'numeric'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
        ]);
        $account = CostAccount::where('account_code', $data['account_code'])->first();
        abort_unless($account && ! $account->is_parent && preg_match('/^[5-9]/', $account->account_code), 422, 'Choose a leaf cost account with code 5–9.');
        abort_if($this->isPeriodClosed($data['period']), 423, 'Report Cost is closed for this period.');

        $amount = round((float) $data['qty'] * (float) $data['rate_usd'], 6);
        abort_if(! is_finite($amount) || $amount === 0.0, 422, 'Accrual amount cannot be zero. Check the quantity and rate.');
        $data['description'] = trim((string) ($data['description'] ?? '')) ?: $account->account_name;
        $data['amount_usd'] = $amount;
        $data['source'] = 'manual';
        $accrual = Accrual::create($data);
        AuditLog::create(['user_id'=>$request->user()?->id,'action'=>'create','resource'=>'accrual','period'=>$data['period'],'payload'=>$accrual->toArray(),'ip_address'=>$request->ip()]);
        return response()->json(['success'=>true,'data'=>$accrual], 201);
    }

    public function destroy(Request $request, Accrual $accrual)
    {
        $period = (string) $request->query('period', '');
        abort_unless($period === $accrual->period, 409, 'The requested period does not match this accrual.');
        abort_if($this->isPeriodClosed($accrual->period), 423, 'Report Cost is closed for this period.');

        $payload = $accrual->toArray();
        $accrual->delete();
        AuditLog::create(['user_id'=>$request->user()?->id,'action'=>'delete','resource'=>'accrual','period'=>$period,'payload'=>$payload,'ip_address'=>$request->ip()]);
        return response()->json(['success'=>true,'message'=>'Manual accrual deleted.']);
    }

    private function isPeriodClosed(string $period): bool
    {
        $overrides = SystemConfig::get('report_cost_closing_overrides', []);
        if (is_string($overrides)) $overrides = json_decode($overrides, true) ?: [];
        return is_array($overrides) && (bool) ($overrides[$period] ?? false);
    }
}
