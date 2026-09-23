<?php

namespace App\Http\Controllers;

use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\SipNumberService;
use App\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SipNumberController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly SipNumberService $numbers,
    ) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenants->forUser($request->user());

        return view('sip-numbers.index', [
            'mode' => 'customer',
            'tenant' => $tenant,
            'myNumbers' => SipNumber::query()
                ->whereBelongsTo($tenant)
                ->where('status', SipNumber::STATUS_ASSIGNED)
                ->with(['providerGateway', 'inboundRoute.destination', 'outboundRoute.gateway'])
                ->orderByDesc('id')
                ->get(),
            'availableNumbers' => SipNumber::query()
                ->whereNull('tenant_id')
                ->where('status', SipNumber::STATUS_AVAILABLE)
                ->with('providerGateway')
                ->orderBy('normalized_number')
                ->get(),
            'myRequests' => SipNumber::query()
                ->where('requested_by_user_id', $request->user()->id)
                ->where('status', SipNumber::STATUS_PENDING)
                ->orderByDesc('id')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();
        $this->tenants->forUser($user);

        $data = $request->validate([
            'number' => ['required', 'string', 'max:32'],
        ]);

        try {
            $result = $this->numbers->requestByod($user, $data['number']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['number' => $e->getMessage()])->withInput();
        }

        if ($result['duplicate']) {
            return back()->withErrors(['number' => 'این شماره قبلاً ثبت شده است.'])->withInput();
        }

        return back()->with('status', 'درخواست شماره ثبت شد و پس از تأیید مدیر فعال می‌شود.');
    }

    public function update(Request $request, int $sipNumber): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        $number = $this->ownedNumber($tenant, $sipNumber);

        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:assigned,disabled'],
            'inbound_enabled' => ['sometimes', 'boolean'],
            'outbound_enabled' => ['sometimes', 'boolean'],
        ]);

        try {
            $this->numbers->updateRoutingFlags($number, $data);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return back()->withErrors(['number' => $e->getMessage()]);
        }

        return back()->with('status', 'شماره SIP به‌روزرسانی شد.');
    }

    public function assign(Request $request, int $sipNumber): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());

        $number = SipNumber::query()
            ->whereNull('tenant_id')
            ->where('status', SipNumber::STATUS_AVAILABLE)
            ->findOrFail($sipNumber);

        try {
            $this->numbers->assignToTenant($number, $tenant);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['number' => $e->getMessage()]);
        }

        return back()->with('status', 'شماره به فضای کاری شما تخصیص یافت.');
    }

    public function release(Request $request, int $sipNumber): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        $number = $this->ownedNumber($tenant, $sipNumber);

        try {
            $this->numbers->releaseFromTenant($number);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['number' => $e->getMessage()]);
        }

        return back()->with('status', 'شماره به سبد موجودی بازگشت. مسیرهای مرتبط حذف شدند.');
    }

    private function ownedNumber(Tenant $tenant, int $id): SipNumber
    {
        return SipNumber::query()
            ->whereBelongsTo($tenant)
            ->whereKey($id)
            ->firstOrFail();
    }
}
