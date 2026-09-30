<?php

namespace App\Http\Controllers;

use App\Models\CallRecord;
use App\Services\CallReportFilterService;
use App\Services\TenantService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CallHistoryController extends Controller
{
    public function __construct(private readonly TenantService $tenants, private readonly CallReportFilterService $reports) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in([CallRecord::INBOUND, CallRecord::OUTBOUND])],
            'status' => ['nullable', Rule::in([CallRecord::ANSWERED, CallRecord::MISSED, CallRecord::FAILED])],
            'range' => ['nullable', Rule::in(['1d', '7d', '30d', 'all', 'custom'])],
            'from' => ['nullable', 'required_if:range,custom', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_if:range,custom', 'date_format:Y-m-d', 'after_or_equal:from'],
            'hour' => ['nullable', 'integer', 'between:0,23'],
            'number' => ['nullable', 'integer', 'min:1'], 'team' => ['nullable', 'integer', 'min:1'],
        ]);
        $tenantId = $request->user()->isAdmin() ? null : $this->tenants->forUser($request->user())->id;
        $this->reports->validateOwnership($tenantId, $filters);
        $query = $this->reports->apply(CallRecord::query(), $tenantId, $filters)
            ->with(['sipNumber:id,number', 'sipExtension:id,extension,display_name', 'callQueue:id,name', 'ivrMenu:id,name']);
        if ($request->user()->hasPermission('recordings.view')) {
            $query->with('recordings');
        }
        $timezone = config('voip.display_timezone', 'Asia/Tehran');
        $now = CarbonImmutable::now($timezone);
        $range = $filters['range'] ?? '30d';
        $query->where('started_at', '<=', $now->utc());
        if ($range === 'custom') {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], $timezone);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], $timezone)->addDay();
            if (isset($filters['hour'])) {
                if ($filters['from'] !== $filters['to']) {
                    throw ValidationException::withMessages(['hour' => 'فیلتر ساعت فقط برای یک روز مشخص قابل استفاده است.']);
                }
                $start = $start->addHours((int) $filters['hour']);
                $end = $start->addHour();
            }
            $query->where('started_at', '>=', $start->utc())->where('started_at', '<', $end->utc());
        } elseif (isset($filters['hour'])) {
            throw ValidationException::withMessages(['hour' => 'برای فیلتر ساعت، یک روز مشخص انتخاب کنید.']);
        } elseif ($range !== 'all') {
            $days = match ($range) {
                '1d' => 0, '7d' => 6, default => 29
            };
            $query->where('started_at', '>=', $now->startOfDay()->subDays($days)->utc());
        }

        return view('calls.index', [
            'calls' => $query->orderByDesc('started_at')->paginate(30)->withQueryString(),
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
