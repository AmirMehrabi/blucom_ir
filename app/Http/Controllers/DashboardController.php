<?php

namespace App\Http\Controllers;

use App\Models\InboundRoute;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipNumber;
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
                ['label' => 'داخلی‌های فعال', 'value' => (string) SipExtension::query()->where('enabled', true)->count(), 'hint' => 'SIP', 'color' => 'blue'],
                ['label' => 'شماره‌های فعال', 'value' => (string) SipNumber::query()->where('enabled', true)->where('status', SipNumber::STATUS_ASSIGNED)->count(), 'hint' => 'DID', 'color' => 'green'],
                ['label' => 'مسیرهای ورودی', 'value' => (string) InboundRoute::query()->where('enabled', true)->count(), 'hint' => 'فعال', 'color' => 'violet'],
                ['label' => 'مسیرهای خروجی', 'value' => (string) OutboundRoute::query()->where('enabled', true)->count(), 'hint' => 'فعال', 'color' => 'amber'],
            ],
            'secondaryStats' => [
                ['label' => 'داخلی‌ها', 'value' => (string) SipExtension::query()->count()],
                ['label' => 'شماره‌ها', 'value' => (string) SipNumber::query()->count()],
            ],
            'numbers' => SipNumber::query()->orderByDesc('id')->limit(8)->get(),
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
