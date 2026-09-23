<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\OutboundRoute;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OutboundRouteController extends Controller
{
    public function __construct(private readonly TenantService $tenants) {}

    public function index(Request $request): View
    {
        $isAdmin = $request->user()?->user_type === UserType::Admin;
        $tenant = $isAdmin ? null : $this->tenants->forUser($request->user());

        return view('outbound-routes.index', [
            'mode' => $isAdmin ? 'admin' : 'customer',
            'routes' => OutboundRoute::query()
                ->when($tenant, fn ($query) => $query->whereBelongsTo($tenant))
                ->with(['sipNumber', 'gateway'])
                ->orderByDesc('id')
                ->get(),
            'numbers' => SipNumber::query()
                ->when($tenant, fn ($query) => $query->whereBelongsTo($tenant))
                ->where('status', SipNumber::STATUS_ASSIGNED)
                ->orderBy('normalized_number')
                ->get(),
            'gateways' => SipGateway::query()->where('enabled', true)->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());

        $data = $request->validate([
            'sip_number_id' => ['required', 'integer'],
            'gateway_id' => ['required', 'integer'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $number = SipNumber::query()
            ->whereBelongsTo($tenant)
            ->whereKey($data['sip_number_id'])
            ->first();

        if ($number === null) {
            return back()->withErrors(['sip_number_id' => 'شماره انتخاب‌شده متعلق به شما نیست.'])->withInput();
        }

        $gateway = SipGateway::query()->where('enabled', true)->whereKey($data['gateway_id'])->first();

        if ($gateway === null) {
            return back()->withErrors(['gateway_id' => 'دروازه انتخاب‌شده معتبر نیست.'])->withInput();
        }

        if (OutboundRoute::query()->where('sip_number_id', $number->id)->exists()) {
            return back()->withErrors(['sip_number_id' => 'برای این شماره قبلاً مسیر خروجی تعریف شده است.'])->withInput();
        }

        OutboundRoute::query()->create([
            'tenant_id' => $tenant->id,
            'sip_number_id' => $number->id,
            'gateway_id' => $gateway->id,
            'enabled' => (bool) $request->boolean('enabled', true),
        ]);

        return back()->with('status', 'مسیر تماس خروجی ثبت شد.');
    }

    public function update(Request $request, int $outboundRoute): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        $route = OutboundRoute::query()->whereBelongsTo($tenant)->findOrFail($outboundRoute);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'gateway_id' => ['sometimes', 'integer'],
        ]);

        if (array_key_exists('gateway_id', $data)) {
            $gateway = SipGateway::query()->where('enabled', true)->whereKey($data['gateway_id'])->first();

            if ($gateway === null) {
                return back()->withErrors(['gateway_id' => 'دروازه انتخاب‌شده معتبر نیست.'])->withInput();
            }
        }

        $route->update($data);

        return back()->with('status', 'مسیر تماس خروجی به‌روزرسانی شد.');
    }

    public function destroy(Request $request, int $outboundRoute): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        OutboundRoute::query()->whereBelongsTo($tenant)->findOrFail($outboundRoute)->delete();

        return redirect()->route('outbound-routes.index')->with('status', 'مسیر تماس خروجی حذف شد.');
    }
}
