<?php

namespace App\Http\Controllers;

use App\Models\InboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Services\CallSummaryService;
use App\Services\TenantService;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly CallSummaryService $calls,
    ) {}

    public function admin(): View
    {
        return view('dashboard', [
            'mode' => 'admin',
            'canViewCalls' => true,
            'callSummary' => $this->calls->summarize(null),
            'configuration' => [
                ['label' => 'شماره‌های فعال', 'value' => SipNumber::query()->where('enabled', true)->where('status', SipNumber::STATUS_ASSIGNED)->count(), 'note' => 'آماده دریافت تماس'],
                ['label' => 'داخلی‌های فعال', 'value' => SipExtension::query()->where('enabled', true)->count(), 'note' => 'آماده پاسخ‌گویی'],
                ['label' => 'دروازه‌های SIP', 'value' => SipGateway::query()->where('enabled', true)->count(), 'note' => 'اتصال فعال'],
            ],
        ]);
    }

    public function customer(Request $request): View
    {
        $tenant = $this->tenants->forUser($request->user());
        $canViewCalls = $request->user()->hasPermission(Permissions::CALLS_VIEW);

        return view('dashboard', [
            'mode' => 'operator',
            'canViewCalls' => $canViewCalls,
            'callSummary' => $canViewCalls ? $this->calls->summarize($tenant->id) : null,
            'configuration' => [
                ['label' => 'خط‌های فعال', 'value' => SipNumber::query()->where('tenant_id', $tenant->id)->where('status', SipNumber::STATUS_ASSIGNED)->where('enabled', true)->count(), 'note' => 'آماده دریافت تماس'],
                ['label' => 'تلفن‌های فعال', 'value' => SipExtension::query()->where('tenant_id', $tenant->id)->where('enabled', true)->count(), 'note' => 'آماده پاسخ‌گویی'],
                ['label' => 'مسیرهای ورودی', 'value' => InboundRoute::query()->where('tenant_id', $tenant->id)->where('enabled', true)->count(), 'note' => 'مسیر فعال'],
            ],
        ]);
    }
}
