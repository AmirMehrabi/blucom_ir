<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlanVersion;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Services\Commerce\NumberInventoryService;
use App\Services\Commerce\NumberOfferService;
use App\Services\Commerce\NumberReadinessService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NumberInventoryController extends Controller
{
    public function store(Request $request, NumberInventoryService $inventory)
    {
        $number = $inventory->create($request->user(), $this->settings($request, true));

        return redirect()->route('admin.inventory.show', $number)->with('status', 'موجودی پیش‌نویس ساخته شد؛ هنوز منتشر نشده است.');
    }

    public function show(int $number, NumberReadinessService $readiness)
    {
        $stock = SipNumber::query()->whereNotNull('inventory_state')->with(['providerGateway', 'currentOffer.planVersion.plan', 'offers.planVersion.plan'])->findOrFail($number);

        return response()->view('admin.commerce.stock', [
            'number' => $stock,
            'gateways' => SipGateway::query()->whereNull('tenant_id')->orderBy('name')->get(),
            'versions' => PlanVersion::query()->whereNotNull('published_at')->whereHas('plan', fn ($q) => $q->where('archived', false))->with('plan')->get(),
            'issues' => $readiness->issues($stock, $stock->providerGateway),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, int $number, NumberInventoryService $inventory)
    {
        $data = $this->settings($request);
        $revision = $this->revision($request);
        $inventory->change($request->user(), $number, $revision, 'update', $data);

        return back()->with('status', 'تنظیمات ذخیره شد؛ بررسی فنی باید دوباره انجام شود.');
    }

    public function review(Request $request, int $number, NumberInventoryService $inventory)
    {
        $data = $request->validate(['evidence' => ['required', 'string', 'min:10', 'max:2000']]);
        $inventory->review($request->user(), $number, $this->revision($request), $data['evidence']);

        return back()->with('status', 'بررسی فنی تنظیمات فعلی ثبت شد.');
    }

    public function publish(Request $request, int $number, NumberOfferService $offers)
    {
        $data = $request->validate([
            'plan_version_id' => ['required', 'integer', 'exists:plan_versions,id'],
            'monthly_amount' => ['required', 'integer', 'min:1', 'max:1000000000000'],
        ]);
        $offers->publish($request->user(), $number, $this->revision($request), (int) $data['plan_version_id'], (int) $data['monthly_amount']);

        return back()->with('status', 'پیشنهاد ماهانه منتشر شد؛ در صورت فعال بودن تجارت، مشتری می‌تواند این خط را خریداری کند.');
    }

    public function withdraw(Request $request, int $number, NumberOfferService $offers)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $offers->withdraw($request->user(), $number, $this->revision($request), $data['reason']);

        return back()->with('status', 'انتشار برداشته شد؛ تاریخچه قیمت محفوظ است.');
    }

    public function transition(Request $request, int $number, NumberInventoryService $inventory)
    {
        $data = $request->validate(['action' => ['required', 'in:disable,draft'], 'reason' => ['required', 'string', 'max:1000']]);
        $inventory->change($request->user(), $number, $this->revision($request), $data['action'], $data);

        return back()->with('status', 'وضعیت موجودی تغییر کرد.');
    }

    private function revision(Request $request): int
    {
        return (int) $request->validate(['revision' => ['required', 'integer', 'min:1']])['revision'];
    }

    private function settings(Request $request, bool $create = false): array
    {
        $data = $request->validate([
            'number' => $create ? ['required', 'string', 'max:32'] : ['prohibited'],
            'label' => ['nullable', 'string', 'max:255'],
            'provider_gateway_id' => ['nullable', 'integer', Rule::exists('sip_gateways', 'id')->whereNull('tenant_id')],
            'enabled' => ['required', 'boolean'], 'inbound_enabled' => ['required', 'boolean'], 'outbound_enabled' => ['required', 'boolean'],
            'destination_prefixes_text' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['destination_prefixes'] = preg_split('/[\s,]+/', trim($data['destination_prefixes_text'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        validator($data, ['destination_prefixes' => ['array', 'max:50'], 'destination_prefixes.*' => ['string', 'regex:/^\+[1-9][0-9]{0,14}$/D']])->validate();

        return $data;
    }
}
