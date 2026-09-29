<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\InboundRouteRequest;
use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Services\BlucomOwner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class InboundRouteController extends Controller
{
    public function __construct(private readonly BlucomOwner $owner) {}

    public function index(Request $request): View
    {
        $tenant = $this->owner->get();

        return view('inbound-routes.index', [
            'mode' => 'admin',
            'routes' => InboundRoute::query()
                ->when($tenant, fn ($query) => $query->whereBelongsTo($tenant))
                ->with(['sipNumber', 'destination'])
                ->orderByDesc('id')
                ->get(),
            'numbers' => SipNumber::query()
                ->when($tenant, fn ($query) => $query->whereBelongsTo($tenant))
                ->where('status', SipNumber::STATUS_ASSIGNED)
                ->where('enabled', true)
                ->where('inbound_enabled', true)
                ->orderBy('normalized_number')
                ->get(),
            'extensions' => SipExtension::query()
                ->when($tenant, fn ($query) => $query->whereBelongsTo($tenant))
                ->where('enabled', true)
                ->orderBy('extension')
                ->get(),
            'queues' => CallQueue::query()->when($tenant, fn ($query) => $query->whereBelongsTo($tenant))
                ->where('enabled', true)->orderBy('name')->get(),
            'menus' => IvrMenu::query()->whereBelongsTo($tenant)->where('enabled', true)
                ->whereNotNull('published_config')->orderBy('name')->get(),
        ]);
    }

    public function store(InboundRouteRequest $request): RedirectResponse
    {
        $tenant = $this->owner->get();

        $data = $request->validated();
        [$destinationType, $destinationId] = $this->choice($data, InboundRoute::DESTINATION_EXTENSION, 0);

        $number = SipNumber::query()
            ->whereBelongsTo($tenant)
            ->whereKey($data['sip_number_id'])
            ->where('enabled', true)
            ->where('inbound_enabled', true)
            ->where('status', SipNumber::STATUS_ASSIGNED)
            ->first();

        if ($number === null) {
            return back()->withErrors(['sip_number_id' => 'شماره انتخاب‌شده متعلق به شما نیست.'])->withInput();
        }

        $destination = $this->destination($destinationType, $destinationId, $tenant->id);
        if ($destination === null) {
            return back()->withErrors(['destination_id' => 'مقصد انتخاب‌شده در دسترس نیست.'])->withInput();
        }

        if (InboundRoute::query()->where('sip_number_id', $number->id)->exists()) {
            return back()->withErrors(['sip_number_id' => 'برای این شماره قبلاً مسیر ورودی تعریف شده است.'])->withInput();
        }

        $route = InboundRoute::query()->create([
            'tenant_id' => $tenant->id,
            'sip_number_id' => $number->id,
            'destination_type' => $destinationType,
            'destination_id' => $destination->id,
            'enabled' => (bool) $request->boolean('enabled', true),
        ]);
        Log::info('Inbound route created', ['inbound_route_id' => $route->id]);

        return back()->with('status', 'مسیر تماس ورودی ثبت شد.');
    }

    public function update(InboundRouteRequest $request, int $inboundRoute): RedirectResponse
    {
        $tenant = $this->owner->get();
        $route = InboundRoute::query()->whereBelongsTo($tenant)->findOrFail($inboundRoute);

        $data = $request->validated();
        [$destinationType, $destinationId] = $this->choice($data, $route->destination_type, $route->destination_id);
        $destinationChanged = $destinationType !== $route->destination_type || (int) $destinationId !== $route->destination_id;
        if ($destinationChanged || (! $route->enabled && (bool) ($data['enabled'] ?? false))) {
            $destination = $this->destination($destinationType, (int) $destinationId, $tenant->id);
            if ($destination === null) {
                return back()->withErrors(['destination_id' => 'مقصد انتخاب‌شده در دسترس نیست.'])->withInput();
            }
        }

        unset($data['destination_choice']);
        $data['destination_type'] = $destinationType;
        $data['destination_id'] = $destinationId;
        $route->update($data);
        Log::info('Inbound route updated', ['inbound_route_id' => $route->id]);

        return back()->with('status', 'مسیر تماس ورودی به‌روزرسانی شد.');
    }

    public function destroy(Request $request, int $inboundRoute): RedirectResponse
    {
        $tenant = $this->owner->get();
        InboundRoute::query()->whereBelongsTo($tenant)->findOrFail($inboundRoute)->delete();
        Log::info('Inbound route deleted', ['inbound_route_id' => $inboundRoute]);

        return redirect()->route('inbound-routes.index')->with('status', 'مسیر تماس ورودی حذف شد.');
    }

    private function destination(string $type, int $id, int $tenantId): SipExtension|CallQueue|IvrMenu|null
    {
        if ($type === InboundRoute::DESTINATION_EXTENSION) {
            return SipExtension::query()->where('tenant_id', $tenantId)->whereKey($id)->where('enabled', true)->first();
        }

        if ($type === InboundRoute::DESTINATION_QUEUE && config('voip.queues_enabled')) {
            return CallQueue::query()->where('tenant_id', $tenantId)->whereKey($id)->where('enabled', true)
                ->whereHas('members', fn ($query) => $query->where('enabled', true))->first();
        }

        if ($type === InboundRoute::DESTINATION_IVR) {
            return IvrMenu::query()->where('tenant_id', $tenantId)->whereKey($id)->where('enabled', true)
                ->whereNotNull('published_config')->first();
        }

        return null;
    }

    private function choice(array $data, string $defaultType, int $defaultId): array
    {
        if (isset($data['destination_choice'])) {
            [$type, $id] = explode(':', $data['destination_choice'], 2);

            return [$type, (int) $id];
        }

        return [$data['destination_type'] ?? $defaultType, (int) ($data['destination_id'] ?? $defaultId)];
    }
}
