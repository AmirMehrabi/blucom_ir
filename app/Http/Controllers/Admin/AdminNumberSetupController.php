<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Services\BlucomOwner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class AdminNumberSetupController extends Controller
{
    public function __construct(private readonly BlucomOwner $owner) {}

    public function show(int $sipNumber): View
    {
        $tenant = $this->owner->get();
        $number = SipNumber::query()->whereBelongsTo($tenant)
            ->with(['providerGateway', 'inboundRoute.destination', 'outboundRoutes.gateway', 'outboundRoutes.sipExtension'])
            ->findOrFail($sipNumber);
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
        $providerReady = $number->providerGateway?->enabled && $number->providerGateway?->tenant_id === null;

        return view('admin.sip-numbers.setup', [
            'number' => $number,
            'gateways' => SipGateway::query()->whereNull('tenant_id')->where('enabled', true)->orderBy('name')->get(),
            'numberReady' => $numberReady,
            'providerReady' => $providerReady,
            'answerReady' => (bool) $answerReady,
            'outboundReady' => $number->outbound_enabled && $outboundReady,
            'outboundRoutes' => $outboundRoutes,
        ]);
    }

    public function updateGateway(Request $request, int $sipNumber): RedirectResponse
    {
        $number = SipNumber::query()->whereBelongsTo($this->owner->get())->findOrFail($sipNumber);
        $data = $request->validate([
            'provider_gateway_id' => ['required', 'integer', Rule::exists('sip_gateways', 'id')
                ->whereNull('tenant_id')->where('enabled', true)],
        ]);
        $number->update(['provider_gateway_id' => $data['provider_gateway_id']]);
        Log::info('DID provider gateway linked', ['sip_number_id' => $number->id, 'gateway_id' => $data['provider_gateway_id']]);

        return redirect()->route('admin.sip-numbers.setup', $number)->with('status', 'دروازه این شماره ذخیره شد. وضعیت اتصال را جداگانه بررسی کنید.');
    }
}
