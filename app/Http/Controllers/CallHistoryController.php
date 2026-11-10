<?php

namespace App\Http\Controllers;

use App\Models\CallRecord;
use App\Services\CallReportFilterService;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CallHistoryController extends Controller
{
    public function __construct(private readonly TenantService $tenants, private readonly CallReportFilterService $reports) {}

    public function index(Request $request): View
    {
        $filters = $this->reports->validated($request);
        $tenantId = $request->user()->isAdmin() ? null : $this->tenants->forUser($request->user())->id;
        $this->reports->validateOwnership($tenantId, $filters);
        $query = $this->reports->apply(CallRecord::query(), $tenantId, $filters)
            ->with(['sipNumber:id,number', 'sipExtension:id,extension,display_name', 'callQueue:id,name', 'ivrMenu:id,name']);
        if ($request->user()->hasPermission('recordings.view')) {
            $query->with('recordings');
        }
        $timezone = config('voip.display_timezone', 'Asia/Tehran');
        $range = $filters['range'];
        $this->reports->applyWindow($query, $filters);

        return view('calls.index', [
            'calls' => $query->orderByDesc('started_at')->orderByDesc('id')->paginate(30)->withQueryString(),
            'filters' => $filters, 'range' => $range, 'timezone' => $timezone,
        ] + $this->reports->options($tenantId));
    }

    public function show(Request $request, CallRecord $callRecord): View
    {
        abort_unless($request->user()->isAdmin() || $callRecord->tenant_id === $this->tenants->forUser($request->user())->id, 404);
        $callRecord->load(['sipNumber', 'sipExtension', 'callQueue', 'ivrMenu']);
        if ($request->user()->hasPermission('recordings.view')) {
            $callRecord->load('recordings');
        }

        return view('calls.show', ['call' => $callRecord, 'timezone' => config('voip.display_timezone', 'Asia/Tehran')]);
    }
}
