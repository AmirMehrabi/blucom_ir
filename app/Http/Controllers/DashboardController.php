<?php

namespace App\Http\Controllers;

use App\Models\InboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Services\CallReportFilterService;
use App\Services\CallSummaryService;
use App\Services\DashboardAttentionService;
use App\Services\TenantService;
use App\Support\DashboardPeriod;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly CallSummaryService $calls,
        private readonly CallReportFilterService $filters,
        private readonly DashboardAttentionService $attention,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        $tenantId = $user->isAdmin() ? null : $this->tenants->forUser($user)->id;
        $canViewCalls = $user->hasPermission(Permissions::CALLS_VIEW);
        $canViewRecordings = $canViewCalls && $user->hasPermission(Permissions::RECORDINGS_VIEW);
        $filters = $request->validate(['number' => ['nullable', 'integer', 'min:1'], 'team' => ['nullable', 'integer', 'min:1']]);
        if (! $canViewCalls) {
            $filters = [];
        }
        $this->filters->validateOwnership($tenantId, $filters);
        $period = array_key_exists($user->dashboard_period ?? '', DashboardPeriod::OPTIONS) ? $user->dashboard_period : 'daily';
        $configuration = [
            ['label' => $user->isAdmin() ? 'شماره‌های فعال' : 'خط‌های فعال', 'value' => SipNumber::query()->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))->where('enabled', true)->where('status', SipNumber::STATUS_ASSIGNED)->count(), 'note' => 'تخصیص‌یافته و فعال در تنظیمات'],
            ['label' => $user->isAdmin() ? 'داخلی‌های فعال' : 'تلفن‌های فعال', 'value' => SipExtension::query()->when($tenantId !== null, fn ($query) => $query->where('tenant_id', $tenantId))->where('enabled', true)->count(), 'note' => 'فعال در تنظیمات؛ وضعیت ثبت تلفن را نشان نمی‌دهد'],
            $user->isAdmin()
                ? ['label' => 'دروازه‌های SIP', 'value' => SipGateway::query()->where('enabled', true)->count(), 'note' => 'فعال در تنظیمات؛ وضعیت اتصال زنده را نشان نمی‌دهد']
                : ['label' => 'مسیرهای ورودی', 'value' => InboundRoute::query()->where('tenant_id', $tenantId)->where('enabled', true)->count(), 'note' => 'فعال در تنظیمات'],
        ];

        return view('dashboard', [
            'mode' => $user->isAdmin() ? 'admin' : 'operator', 'period' => $period, 'periods' => DashboardPeriod::OPTIONS,
            'canViewCalls' => $canViewCalls, 'canViewRecordings' => $canViewRecordings,
            'callSummary' => $canViewCalls ? $this->calls->summarize($tenantId, $period, $filters, $canViewRecordings) : null,
            'configuration' => $configuration, 'filters' => $filters,
            'attention' => $this->attention->summarize($user, $tenantId),
        ] + ($canViewCalls ? $this->filters->options($tenantId) : ['numbers' => collect(), 'teams' => collect()]));
    }

    public function preference(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'period' => ['required', Rule::in(array_keys(DashboardPeriod::OPTIONS))],
            'number' => ['nullable', 'integer', 'min:1'], 'team' => ['nullable', 'integer', 'min:1'],
        ]);
        $filters = $request->user()->hasPermission(Permissions::CALLS_VIEW) ? array_filter(Arr::only($data, ['number', 'team'])) : [];
        $tenantId = $request->user()->isAdmin() ? null : $this->tenants->forUser($request->user())->id;
        $this->filters->validateOwnership($tenantId, $filters);
        $request->user()->forceFill(['dashboard_period' => $data['period']])->save();

        return redirect()->route('dashboard', $filters);
    }
}
