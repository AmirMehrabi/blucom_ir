<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\InboundRoute;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InboundRouteController extends Controller
{
    public function __construct(private readonly TenantService $tenants) {}

    public function index(Request $request): View
    {
        $isAdmin = $request->user()?->user_type === UserType::Admin;
        $tenant = $isAdmin ? null : $this->tenants->forUser($request->user());

        return view('inbound-routes.index', [
            'mode' => $isAdmin ? 'admin' : 'customer',
            'routes' => InboundRoute::query()
                ->when($tenant, fn ($query) => $query->whereBelongsTo($tenant))
                ->with(['sipNumber', 'destination'])
                ->orderByDesc('id')
                ->get(),
            'numbers' => SipNumber::query()
                ->when($tenant, fn ($query) => $query->whereBelongsTo($tenant))
                ->where('status', SipNumber::STATUS_ASSIGNED)
                ->orderBy('normalized_number')
                ->get(),
            'extensions' => SipExtension::query()
                ->when($tenant, fn ($query) => $query->whereBelongsTo($tenant))
                ->where('enabled', true)
                ->orderBy('extension')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());

        $data = $request->validate([
            'sip_number_id' => ['required', 'integer'],
            'destination_id' => ['required', 'integer'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $number = SipNumber::query()
            ->whereBelongsTo($tenant)
            ->whereKey($data['sip_number_id'])
            ->first();

        if ($number === null) {
            return back()->withErrors(['sip_number_id' => 'شماره انتخاب‌شده متعلق به شما نیست.'])->withInput();
        }

        $extension = SipExtension::query()
            ->whereBelongsTo($tenant)
            ->whereKey($data['destination_id'])
            ->first();

        if ($extension === null) {
            return back()->withErrors(['destination_id' => 'داخلی انتخاب‌شده متعلق به شما نیست.'])->withInput();
        }

        if (InboundRoute::query()->where('sip_number_id', $number->id)->exists()) {
            return back()->withErrors(['sip_number_id' => 'برای این شماره قبلاً مسیر ورودی تعریف شده است.'])->withInput();
        }

        InboundRoute::query()->create([
            'tenant_id' => $tenant->id,
            'sip_number_id' => $number->id,
            'destination_type' => 'extension',
            'destination_id' => $extension->id,
            'enabled' => (bool) $request->boolean('enabled', true),
        ]);

        return back()->with('status', 'مسیر تماس ورودی ثبت شد.');
    }

    public function update(Request $request, int $inboundRoute): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        $route = InboundRoute::query()->whereBelongsTo($tenant)->findOrFail($inboundRoute);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'destination_id' => ['sometimes', 'integer'],
        ]);

        if (array_key_exists('destination_id', $data)) {
            $extension = SipExtension::query()
                ->whereBelongsTo($tenant)
                ->whereKey($data['destination_id'])
                ->first();

            if ($extension === null) {
                return back()->withErrors(['destination_id' => 'داخلی انتخاب‌شده متعلق به شما نیست.'])->withInput();
            }
        }

        $route->update($data);

        return back()->with('status', 'مسیر تماس ورودی به‌روزرسانی شد.');
    }

    public function destroy(Request $request, int $inboundRoute): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        InboundRoute::query()->whereBelongsTo($tenant)->findOrFail($inboundRoute)->delete();

        return redirect()->route('inbound-routes.index')->with('status', 'مسیر تماس ورودی حذف شد.');
    }
}
