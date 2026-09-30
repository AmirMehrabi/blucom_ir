<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Services\AdminLineSetupService;
use App\Services\BlucomOwner;
use App\Services\InboundScheduleService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AdminNumberSetupController extends Controller
{
    public function __construct(private readonly BlucomOwner $owner) {}

    public function show(Request $request, int $sipNumber)
    {
        $number = SipNumber::query()
            ->with(['tenant', 'providerGateway', 'inboundRoute.destination', 'outboundRoutes.gateway', 'outboundRoutes.sipExtension'])
            ->findOrFail($sipNumber);
        abort_if($number->tenant === null, 404);
        $tenant = $number->tenant;
        $route = $number->inboundRoute;
        $destination = $route?->destination;
        $answerReady = $route?->enabled && $destination?->tenant_id === $tenant->id
            && match (true) {
                $destination instanceof SipExtension => $destination->enabled,
                $destination instanceof CallQueue => config('voip.queues_enabled') && $destination->enabled
                    && $destination->members()->where('enabled', true)->exists(),
                $destination instanceof IvrMenu => $destination->isPublished()
                    && Storage::disk('ivr')->exists((string) ($destination->published_config['greeting'] ?? '')),
                default => false,
            };
        $outboundRoutes = $number->outboundRoutes;
        $outboundReady = $outboundRoutes->contains(fn ($outbound) => $outbound->enabled
            && $outbound->gateway?->enabled && $outbound->gateway?->approved_for_outbound
            && ($number->provider_gateway_id === null || $outbound->gateway_id === $number->provider_gateway_id)
            && $outbound->sipExtension?->enabled
            && $outbound->sipExtension?->tenant_id === $tenant->id);
        $numberReady = $number->status === SipNumber::STATUS_ASSIGNED && $number->enabled;
        $providerReady = $number->providerGateway?->enabled && ($number->providerGateway?->tenant_id === null
            || ($number->providerGateway?->tenant_id === $tenant->id && $number->providerGateway?->verification_status === SipGateway::STATUS_APPROVED));

        return response()->view('admin.sip-numbers.setup', [
            'number' => $number,
            'tenant' => $tenant,
            'tab' => in_array($request->query('tab'), ['inbound', 'outbound', 'settings']) ? $request->query('tab') : 'overview',
            'openNow' => $route ? app(InboundScheduleService::class)->isOpen($route) : null,
            'gateways' => app(AdminLineSetupService::class)->gateways($tenant)->orderBy('name')->get(),
            'numberReady' => $numberReady,
            'providerReady' => $providerReady,
            'answerReady' => (bool) $answerReady,
            'outboundReady' => $number->outbound_enabled && $outboundReady,
            'outboundRoutes' => $outboundRoutes,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function updateGateway(Request $request, int $sipNumber): RedirectResponse
    {
        $number = SipNumber::query()->with('tenant')->findOrFail($sipNumber);
        $data = $request->validate([
            'provider_gateway_id' => ['required', 'integer'],
        ]);
        abort_if($number->tenant === null, 404);
        if (! app(AdminLineSetupService::class)->gateways($number->tenant)->whereKey($data['provider_gateway_id'])->exists()) {
            return back()->withErrors(['provider_gateway_id' => 'دروازه باید فعال و مجاز برای همین مالک باشد.']);
        }
        if ($number->outboundRoutes()->where('gateway_id', '!=', $data['provider_gateway_id'])->exists()) {
            return back()->withErrors(['provider_gateway_id' => 'برای تغییر هماهنگ دروازه و مسیرهای خروجی، از راه‌اندازی سریع استفاده کنید.']);
        }
        $number->update(['provider_gateway_id' => $data['provider_gateway_id']]);
        Log::info('DID provider gateway linked', ['sip_number_id' => $number->id, 'gateway_id' => $data['provider_gateway_id']]);

        return redirect()->route('admin.sip-numbers.setup', $number)->with('status', 'دروازه این شماره ذخیره شد. وضعیت اتصال را جداگانه بررسی کنید.');
    }
}
