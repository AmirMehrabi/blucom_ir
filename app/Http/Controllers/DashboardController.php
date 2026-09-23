<?php

namespace App\Http\Controllers;

use App\Models\InboundRoute;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __construct(private readonly TenantService $tenants) {}

    public function admin(): View
    {
        return view('dashboard', [
            'mode' => 'admin',
            'stats' => [
                ['label' => 'سازمان‌ها', 'value' => (string) Tenant::query()->count(), 'hint' => 'کل', 'color' => 'blue'],
                ['label' => 'شماره‌های تخصیص‌یافته', 'value' => (string) SipNumber::query()->where('status', SipNumber::STATUS_ASSIGNED)->count(), 'hint' => 'فعال', 'color' => 'green'],
                ['label' => 'شماره‌های موجود', 'value' => (string) SipNumber::query()->where('status', SipNumber::STATUS_AVAILABLE)->count(), 'hint' => 'قابل تخصیص', 'color' => 'violet'],
                ['label' => 'در انتظار تأیید', 'value' => (string) SipNumber::query()->where('status', SipNumber::STATUS_PENDING)->count(), 'hint' => 'BYOD', 'color' => 'amber'],
            ],
            'secondaryStats' => [
                ['label' => 'داخلی‌ها', 'value' => (string) SipExtension::query()->where('enabled', true)->count()],
                ['label' => 'دروازه‌های فعال', 'value' => (string) SipGateway::query()->where('enabled', true)->count()],
                ['label' => 'مسیرهای ورودی', 'value' => (string) InboundRoute::query()->where('enabled', true)->count()],
                ['label' => 'مسیرهای خروجی', 'value' => (string) OutboundRoute::query()->where('enabled', true)->count()],
            ],
            'numbers' => SipNumber::query()->with('tenant')->orderByDesc('id')->limit(8)->get(),
        ]);
    }

    public function customer(Request $request): View
    {
        $tenant = $this->tenants->forUser($request->user());

        return view('dashboard', [
            'mode' => 'customer',
            'stats' => [
                ['label' => 'شماره‌های من', 'value' => (string) SipNumber::query()->whereBelongsTo($tenant)->where('status', SipNumber::STATUS_ASSIGNED)->count(), 'hint' => 'تخصیص‌یافته', 'color' => 'blue'],
                ['label' => 'موجود برای تخصیص', 'value' => (string) SipNumber::query()->whereNull('tenant_id')->where('status', SipNumber::STATUS_AVAILABLE)->count(), 'hint' => 'سبد', 'color' => 'green'],
                ['label' => 'داخلی‌ها', 'value' => (string) SipExtension::query()->whereBelongsTo($tenant)->where('enabled', true)->count(), 'hint' => 'فعال', 'color' => 'violet'],
                ['label' => 'در انتظار تأیید', 'value' => (string) SipNumber::query()->where('requested_by_user_id', $request->user()->id)->where('status', SipNumber::STATUS_PENDING)->count(), 'hint' => 'BYOD', 'color' => 'amber'],
            ],
            'secondaryStats' => [
                ['label' => 'مسیرهای ورودی', 'value' => (string) InboundRoute::query()->whereBelongsTo($tenant)->where('enabled', true)->count()],
                ['label' => 'مسیرهای خروجی', 'value' => (string) OutboundRoute::query()->whereBelongsTo($tenant)->where('enabled', true)->count()],
            ],
            'numbers' => SipNumber::query()->whereBelongsTo($tenant)->with('inboundRoute.destination')->orderByDesc('id')->limit(8)->get(),
        ]);
    }
}
