<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DidRequest;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\BlucomOwner;
use App\Services\NumberNormalizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class AdminDidController extends Controller
{
    public function __construct(private readonly BlucomOwner $owner, private readonly NumberNormalizer $numbers) {}

    public function index(Request $request): View
    {
        $tenant = $this->owner->get();

        return view('admin.sip-numbers.index', [
            'mode' => 'admin',
            'numbers' => SipNumber::query()->whereNotNull('tenant_id')
                ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))
                ->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q
                    ->where('normalized_number', 'like', '%'.trim($request->string('q')).'%')
                    ->orWhere('label', 'like', '%'.trim($request->string('q')).'%')))
                ->when($request->filled('gateway_id'), fn ($q) => $q->where('provider_gateway_id', $request->integer('gateway_id')))
                ->when($request->query('filter') === 'scheduled', fn ($q) => $q->whereHas('inboundRoute', fn ($q) => $q->whereNotNull('schedule')))
                ->when($request->query('filter') === 'missing_route', fn ($q) => $q->whereDoesntHave('inboundRoute'))
                ->when($request->query('filter') === 'disabled', fn ($q) => $q->where('enabled', false))
                ->with(['tenant', 'inboundRoute.destination', 'providerGateway'])
                ->withCount(['outboundRoutes as active_outbound_routes_count' => fn ($query) => $query->where('enabled', true)])
                ->orderBy('normalized_number')->paginate(50)->withQueryString(),
            'tenants' => Tenant::query()->orderBy('name')->get(),
            'gateways' => SipGateway::query()->orderBy('name')->get(),
        ]);
    }

    public function store(DidRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $normalized = $this->numbers->normalizeOrFail($data['number']);
        validator(['normalized_number' => $normalized], [
            'normalized_number' => ['unique:sip_numbers,normalized_number'],
        ])->validate();

        $number = SipNumber::query()->create([
            'tenant_id' => $this->owner->get()->id,
            'number' => $data['number'],
            'normalized_number' => $normalized,
            'label' => $data['label'] ?? null,
            'status' => SipNumber::STATUS_ASSIGNED,
            'enabled' => $data['enabled'],
            'inbound_enabled' => $data['inbound_enabled'],
            'outbound_enabled' => $data['outbound_enabled'],
        ]);
        Log::info('DID created', ['sip_number_id' => $number->id]);

        return redirect()->route('admin.sip-numbers.setup', $number)->with('status', 'شماره ثبت شد. مراحل راه‌اندازی را کامل کنید.');
    }

    public function update(DidRequest $request, int $sipNumber): RedirectResponse
    {
        $number = SipNumber::query()->whereNotNull('tenant_id')->findOrFail($sipNumber);
        $data = $request->validated();
        $number->update($data);
        Log::info('DID updated', ['sip_number_id' => $number->id]);

        return redirect()->route('admin.sip-numbers.setup', ['sip_number' => $number->id, 'tab' => 'settings'])
            ->with('status', 'شماره به‌روزرسانی شد.');
    }

    public function destroy(int $sipNumber): RedirectResponse
    {
        $number = SipNumber::query()->whereBelongsTo($this->owner->get())->findOrFail($sipNumber);

        if ($number->inboundRoute()->exists() || $number->outboundRoutes()->exists()) {
            return back()->withErrors(['number' => 'ابتدا مسیرهای وابسته به این شماره را حذف کنید.']);
        }

        $number->delete();
        Log::info('DID deleted', ['sip_number_id' => $number->id]);

        return back()->with('status', 'شماره حذف شد.');
    }
}
