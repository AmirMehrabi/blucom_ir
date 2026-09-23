<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\SipNumberService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AdminSipNumberController extends Controller
{
    public function __construct(private readonly SipNumberService $numbers) {}

    public function index(Request $request): View
    {
        $search = trim((string) $request->query('q', ''));
        $status = (string) $request->query('status', '');

        return view('admin.sip-numbers.index', [
            'mode' => 'admin',
            'numbers' => SipNumber::query()
                ->with(['tenant', 'providerGateway', 'requestedBy'])
                ->when($search !== '', function ($query) use ($search) {
                    $query->where(function ($q) use ($search) {
                        $q->where('number', 'like', '%'.$search.'%')
                            ->orWhere('normalized_number', 'like', '%'.$search.'%');
                    });
                })
                ->when($status !== '', fn ($query) => $query->where('status', $status))
                ->orderByDesc('id')
                ->paginate(50)
                ->withQueryString(),
            'tenants' => Tenant::query()->orderBy('name')->get(['id', 'name', 'status']),
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

        try {
            $this->numbers->createInventory($data);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return back()->withErrors(['number' => $e->getMessage()])->withInput();
        }

        return redirect()->route('admin.sip-numbers.index')->with('status', 'شماره به موجودی اضافه شد.');
    }

    public function update(Request $request, int $sipNumber): RedirectResponse
    {
        $number = SipNumber::query()->findOrFail($sipNumber);

        $data = $request->validate([
            'status' => ['sometimes', 'string', 'in:available,assigned,disabled'],
            'provider_gateway_id' => ['nullable', 'integer', 'exists:sip_gateways,id'],
            'tenant_id' => ['nullable', 'integer', 'exists:tenants,id'],
        ]);

        try {
            $this->numbers->updateByAdmin($number, $data);
        } catch (\RuntimeException|\InvalidArgumentException $e) {
            return back()->withErrors(['number' => $e->getMessage()]);
        }

        return redirect()->route('admin.sip-numbers.index')->with('status', 'شماره به‌روزرسانی شد.');
    }

    public function destroy(int $sipNumber): RedirectResponse
    {
        $number = SipNumber::query()->findOrFail($sipNumber);

        if ($number->status === SipNumber::STATUS_PENDING) {
            return back()->withErrors(['number' => 'ابتدا درخواست را تأیید یا رد کنید.']);
        }

        $number->delete();

        return redirect()->route('admin.sip-numbers.index')->with('status', 'شماره حذف شد.');
    }

    public function approve(Request $request, int $sipNumber): RedirectResponse
    {
        $number = SipNumber::query()->findOrFail($sipNumber);

        $data = $request->validate([
            'disposition' => ['required', 'in:assign,available'],
        ]);

        try {
            $this->numbers->approveByod($number, $data['disposition']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['number' => $e->getMessage()]);
        }

        return redirect()->route('admin.sip-numbers.index')->with('status', 'درخواست شماره تأیید شد.');
    }

    public function reject(int $sipNumber): RedirectResponse
    {
        $number = SipNumber::query()->findOrFail($sipNumber);

        try {
            $this->numbers->rejectByod($number);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['number' => $e->getMessage()]);
        }

        return redirect()->route('admin.sip-numbers.index')->with('status', 'درخواست شماره رد شد.');
    }
}
