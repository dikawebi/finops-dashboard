<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use App\Models\SystemConfig;
class EnsurePeriodOpen
{
    public function handle(Request $request, Closure $next)
    {
        $period = (string) ($request->input('period') ?: $request->query('period', ''));
        $locked = SystemConfig::get('locked_periods', []);
        if ($period !== '' && in_array($period, (array) $locked, true)) return response()->json(['message' => 'Period is locked.'], 423);
        return $next($request);
    }
}
