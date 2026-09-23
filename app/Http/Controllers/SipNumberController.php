<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Services\NumberNormalizer;
use App\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SipNumberController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly NumberNormalizer $numbers,
    ) {}

    public function index(Request $request): View
    {
        $isAdmin = $request->user()?->user_type === UserType::Admin;
        $tenant = $isAdmin ? null : $this->tenants->forUser($request->user());

        $numbers = SipNumber::query()
            ->when($tenant, fn ($query) => $query->whereBelongsTo($tenant))
            ->with(['providerGateway', 'inboundRoute.destination', 'outboundRoute.gateway'])
            ->orderByDesc('id')
            ->get();

        return view('sip-numbers.index', [
            'mode' => $isAdmin ? 'admin' : 'customer',
            'numbers' => $numbers,
            'gateways' => SipGateway::query()->where('enabled', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'number' => ['required', 'string', 'max:32'],
            'provider_gateway_id' => ['nullable', 'integer', 'exists:sip_gateways,id'],
            'inbound_enabled' => ['sometimes', 'boolean'],
            'outbound_enabled' => ['sometimes', 'boolean'],
        ]);

        $normalized = $this->numbers->normalizeOrFail($data['number']);

        if ($data['provider_gateway_id'] ?? null) {
            SipGateway::query()->whereKey($data['provider_gateway_id'])->where('enabled', true)->firstOrFail();
        }

        if (SipNumber::query()->where('normalized_number', $normalized)->exists()) {
            return back()->withErrors(['number' => 'این شماره قبلاً ثبت شده است.'])->withInput();
        }

        $tenant = $this->tenants->forUser($request->user());

        SipNumber::query()->create([
            'tenant_id' => $tenant->id,
            'number' => $data['number'],
            'normalized_number' => $normalized,
            'provider_gateway_id' => $data['provider_gateway_id'] ?? null,
            'status' => 'active',
            'inbound_enabled' => (bool) $request->boolean('inbound_enabled', true),
            'outbound_enabled' => (bool) $request->boolean('outbound_enabled', true),
        ]);

        return back()->with('status', 'شماره SIP ثبت شد.');
    }

    public function update(Request $request, int $sipNumber): RedirectResponse
    {
        $number = $this->scopedNumber($request, $sipNumber);

        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,disabled'],
            'inbound_enabled' => ['sometimes', 'boolean'],
            'outbound_enabled' => ['sometimes', 'boolean'],
            'provider_gateway_id' => ['nullable', 'integer', 'exists:sip_gateways,id'],
        ]);

        if (array_key_exists('provider_gateway_id', $data) && $data['provider_gateway_id'] !== null) {
            SipGateway::query()->whereKey($data['provider_gateway_id'])->where('enabled', true)->firstOrFail();
        }

        $number->update($data);

        return back()->with('status', 'شماره SIP به‌روزرسانی شد.');
    }

    public function destroy(Request $request, int $sipNumber): RedirectResponse
    {
        $number = $this->scopedNumber($request, $sipNumber);
        $number->delete();

        return redirect()->route('sip-numbers.index')->with('status', 'شماره SIP حذف شد.');
    }

    private function scopedNumber(Request $request, int $id): SipNumber
    {
        if ($request->user()?->user_type === UserType::Admin) {
            return SipNumber::query()->findOrFail($id);
        }

        $tenant = $this->tenants->forUser($request->user());

        return SipNumber::query()->whereBelongsTo($tenant)->findOrFail($id);
    }
}
