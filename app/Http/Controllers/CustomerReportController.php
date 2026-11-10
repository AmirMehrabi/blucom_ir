<?php

namespace App\Http\Controllers;

use App\Models\CallRecord;
use App\Services\CallOutcomeClassifier;
use App\Services\CallReportFilterService;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CustomerReportController extends Controller
{
    public function index(Request $request, CallReportFilterService $reports, TenantService $tenants): View
    {
        abort_if($request->user()->isAdmin(), 403);
        $tenantId = $tenants->forUser($request->user())->id;
        $filters = $reports->validated($request, '1d');
        $reports->validateOwnership($tenantId, $filters);
        $query = $reports->applyWindow($reports->apply(CallRecord::query(), $tenantId, $filters), $filters);
        $stats = (clone $query)->selectRaw("COUNT(*) AS total,
            COALESCE(SUM(CASE WHEN direction = 'inbound' THEN 1 ELSE 0 END), 0) AS incoming,
            COALESCE(SUM(CASE WHEN direction = 'outbound' THEN 1 ELSE 0 END), 0) AS outgoing,
            COALESCE(SUM(CASE WHEN status = 'answered' THEN 1 ELSE 0 END), 0) AS answered,
            COALESCE(SUM(CASE WHEN status = 'missed' THEN 1 ELSE 0 END), 0) AS missed,
            COALESCE(SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END), 0) AS failed")->first();

        return view('reports.index', [
            'outcomeCounts' => (clone $query)->selectRaw(CallOutcomeClassifier::expression().' AS result, COUNT(*) AS total')->groupBy('result')->pluck('total', 'result'),
            'stats' => $stats, 'filters' => $filters, 'range' => $filters['range'],
            'recentCalls' => (clone $query)->orderByDesc('started_at')->orderByDesc('id')->limit(6)->get(),
            'timezone' => config('voip.display_timezone', 'Asia/Tehran'),
        ] + $reports->options($tenantId));
    }
}
