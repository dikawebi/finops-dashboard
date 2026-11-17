<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureDashboardAccess
{
    private const API_PAGES = [
        'accounts' => ['cost', 'variance', 'financial', 'glReview'],
        'accruals' => ['cost'],
        'config' => ['config'],
        'gl-data' => ['glReview', 'cost'],
        'gl-transaction-review' => ['glReview'],
        'upload-gl' => ['glReview', 'cost'],
        'cost-matrix' => ['overview', 'cost'],
        'report-cost-closing' => ['cost'],
        'financial-statements' => ['financial'],
        'financial-mappings' => ['financial'],
        'inventory-valuation' => ['overview', 'inventory'],
        'inventory-inputs' => ['inventory'],
        'production-summary' => ['overview'],
        'overview-daily' => ['overview'],
        'overview-allocation' => ['overview'],
        'equipment-cost' => ['equipmentCost'],
        'cost-per-mt' => ['metric'],
        'excel-cost-example' => ['metric'],
        'db-cost-summary' => ['metric', 'equipmentCost'],
        'cost-variance' => ['variance'],
        'integration-status' => ['integration'],
        'google-sheets' => ['integration'],
        'd365/sync' => ['integration'],
        'd365/sync/stop' => ['integration'],
        'd365/progress' => ['integration'],
        'd365/entities' => ['integration'],
        'd365/staging' => ['integration', 'mapping'],
        'd365/staging/import' => ['mapping'],
        'd365/staging/preview' => ['integration', 'mapping'],
        'd365/mappings' => ['mapping'],
        'd365/account-hierarchy' => ['mapping'],
        'd365' => ['integration', 'mapping'],
    ];

    private const READ_ONLY_ACTIONS = ['d365/staging/preview', 'google-sheets/preview'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $uri = trim((string) $request->route()?->uri(), '/');

        if ($uri === '') {
            $page = (string) $request->query('view', 'overview');
            if (! $user?->canAccessPage($page)) {
                $firstAllowed = array_key_first(array_filter(\App\Models\DashboardRole::pages(), fn ($_, $candidate) => $user?->canAccessPage($candidate), ARRAY_FILTER_USE_BOTH));
                abort_unless($firstAllowed, 403, 'No dashboard pages are assigned to this account.');
                return redirect()->route('dashboard', ['view' => $firstAllowed]);
            }

            return $next($request);
        }

        if (! str_starts_with($uri, 'api/')) {
            return $next($request);
        }

        $endpoint = substr($uri, 4);
        $pageSet = self::API_PAGES[$endpoint] ?? null;
        if ($pageSet === null) {
            foreach (self::API_PAGES as $prefix => $pages) {
                if (str_starts_with($endpoint, $prefix.'/')) {
                    $pageSet = $pages;
                    break;
                }
            }
        }
        if ($pageSet === null) {
            return $next($request);
        }

        $write = ! in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)
            && ! in_array($endpoint, self::READ_ONLY_ACTIONS, true);
        foreach ($pageSet as $page) {
            if ($user?->canAccessPage($page, $write)) {
                return $next($request);
            }
        }

        abort(403, 'Your role does not have access to this dashboard page or action.');
    }
}
