<?php

namespace App\Http\Controllers;

use App\Models\CallRecord;
use App\Services\TenantService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CallHistoryController extends Controller
{
    public function __construct(private readonly TenantService $tenants) {}

    public function index(Request $request): View
    {
        $filters = $request->validate([
            'direction' => ['nullable', Rule::in([CallRecord::INBOUND, CallRecord::OUTBOUND])],
            'status' => ['nullable', Rule::in([CallRecord::ANSWERED, CallRecord::MISSED, CallRecord::FAILED])],
            'range' => ['nullable', Rule::in(['7d', '30d', 'all'])],
        ]);

        $query = CallRecord::query()->with(['sipNumber:id,number', 'sipExtension:id,extension,display_name']);
        if (! $request->user()->isAdmin()) {
            $query->where('tenant_id', $this->tenants->forUser($request->user())->id);
        }
        if (! empty($filters['direction'])) {
            $query->where('direction', $filters['direction']);
        }
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        $range = $filters['range'] ?? '30d';
        if ($range !== 'all') {
            $days = $range === '7d' ? 6 : 29;
            $start = CarbonImmutable::now(config('voip.display_timezone', 'Asia/Tehran'))
                ->startOfDay()->subDays($days)->utc();
            $query->where('started_at', '>=', $start);
        }

        return view('calls.index', [
            'calls' => $query->orderByDesc('started_at')->paginate(30)->withQueryString(),
            'filters' => $filters,
            'range' => $range,
            'timezone' => config('voip.display_timezone', 'Asia/Tehran'),
        ]);
    }
}
