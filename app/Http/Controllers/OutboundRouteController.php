<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\OutboundRouteRequest;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Services\AdminLineSetupService;
use App\Services\AdminVoipScope;
use App\Services\BlucomOwner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class OutboundRouteController extends Controller
{
    public function __construct(private readonly BlucomOwner $owner) {}

    public function index(Request $request, AdminVoipScope $scope): View
    {
        $tenant = $scope->tenant($request);
        $numbers = SipNumber::query()
            ->whereBelongsTo($tenant)
            ->where('status', SipNumber::STATUS_ASSIGNED)
            ->where('enabled', true)
            ->where('outbound_enabled', true)
            ->orderBy('normalized_number')->get();

        return view('outbound-routes.index', [
            'mode' => 'admin',
            'tenant' => $tenant,
            'selectedNumberId' => $numbers->firstWhere('id', $request->integer('sip_number_id'))?->id,
            'routes' => OutboundRoute::query()
                ->when($tenant, fn ($query) => $query->whereBelongsTo($tenant))
                ->with(['sipNumber', 'gateway', 'sipExtension'])
                ->orderByDesc('id')
                ->get(),
            'numbers' => $numbers,
            'gateways' => app(AdminLineSetupService::class)->gateways($tenant)->where('approved_for_outbound', true)->orderBy('name')->get(),
            'extensions' => SipExtension::query()->whereBelongsTo($tenant)->orderBy('extension')->get(),
        ]);
    }

    public function store(OutboundRouteRequest $request, AdminVoipScope $scope): RedirectResponse
    {
        $tenant = $scope->tenant($request);

        $data = $request->validated();

        $number = SipNumber::query()
            ->whereBelongsTo($tenant)
            ->whereKey($data['sip_number_id'])
            ->where('enabled', true)
            ->where('outbound_enabled', true)
            ->where('status', SipNumber::STATUS_ASSIGNED)
            ->first();

        if ($number === null) {
            return back()->withErrors(['sip_number_id' => 'شماره انتخاب‌شده متعلق به شما نیست.'])->withInput();
        }

        $extension = SipExtension::query()->whereBelongsTo($tenant)->whereKey($data['sip_extension_id'])->first();

        if ($extension === null) {
            return back()->withErrors(['sip_extension_id' => 'داخلی انتخاب‌شده معتبر نیست.'])->withInput();
        }

        $gateway = app(AdminLineSetupService::class)->gateways($tenant)->whereKey($data['gateway_id'])->first();

        if ($gateway === null || ! $gateway->approved_for_outbound || ($number->provider_gateway_id !== null && $number->provider_gateway_id !== $gateway->id)) {
            return back()->withErrors(['gateway_id' => 'دروازه انتخاب‌شده معتبر نیست.'])->withInput();
        }

        if (OutboundRoute::query()->where('sip_extension_id', $extension->id)->exists()) {
            return back()->withErrors(['sip_extension_id' => 'برای این داخلی قبلاً مسیر خروجی تعریف شده است.'])->withInput();
        }

        $route = OutboundRoute::query()->create([
            'tenant_id' => $tenant->id,
            'sip_extension_id' => $extension->id,
            'sip_number_id' => $number->id,
            'gateway_id' => $gateway->id,
            'enabled' => (bool) $request->boolean('enabled', true),
        ]);
        Log::info('Outbound route created', ['outbound_route_id' => $route->id]);

        return back()->with('status', 'مسیر تماس خروجی ثبت شد.');
    }

    public function update(OutboundRouteRequest $request, int $outboundRoute): RedirectResponse
    {
        $route = OutboundRoute::query()->with('tenant')->findOrFail($outboundRoute);
        $tenant = $route->tenant;

        $data = $request->validated();

        if (array_key_exists('sip_number_id', $data)) {
            $number = SipNumber::query()->whereBelongsTo($tenant)
                ->whereKey($data['sip_number_id'])
                ->where('status', SipNumber::STATUS_ASSIGNED)
                ->where('enabled', true)
                ->where('outbound_enabled', true)
                ->first();

            if ($number === null) {
                return back()->withErrors(['sip_number_id' => 'شماره انتخاب‌شده معتبر نیست.'])->withInput();
            }
        }

        if (array_key_exists('gateway_id', $data)) {
            $gateway = app(AdminLineSetupService::class)->gateways($tenant)->whereKey($data['gateway_id'])->first();

            if ($gateway === null || ! $gateway->approved_for_outbound) {
                return back()->withErrors(['gateway_id' => 'دروازه انتخاب‌شده معتبر نیست.'])->withInput();
            }
        }

        $number = SipNumber::query()->whereBelongsTo($tenant)->find($data['sip_number_id'] ?? $route->sip_number_id);
        $gatewayId = $data['gateway_id'] ?? $route->gateway_id;
        if ($number === null || ($number->provider_gateway_id !== null && $number->provider_gateway_id !== $gatewayId)) {
            return back()->withErrors(['gateway_id' => 'دروازه خروجی باید با دروازه همین شماره یکسان باشد.']);
        }
        $route->update($data);
        Log::info('Outbound route updated', ['outbound_route_id' => $route->id]);

        return back()->with('status', 'مسیر تماس خروجی به‌روزرسانی شد.');
    }

    public function destroy(Request $request, int $outboundRoute): RedirectResponse
    {
        OutboundRoute::query()->findOrFail($outboundRoute)->delete();
        Log::info('Outbound route deleted', ['outbound_route_id' => $outboundRoute]);

        return redirect()->route('outbound-routes.index')->with('status', 'مسیر تماس خروجی حذف شد.');
    }
}
