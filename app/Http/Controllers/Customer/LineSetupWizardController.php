<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\LineSetupWizard;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Services\CustomerLineSetupService;
use App\Services\IvrMenuService;
use App\Services\TenantService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class LineSetupWizardController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly CustomerLineSetupService $setup,
        private readonly IvrMenuService $ivrMenus,
    ) {}

    public function show(Request $request): View
    {
        $this->authorizeWizard($request);
        $tenant = $this->tenants->forUser($request->user());
        $wizard = LineSetupWizard::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'created_by_user_id' => $request->user()->id],
        );
        $gateways = $tenant->sipGateways()->orderByDesc('id')->get();
        $gateway = $gateways->firstWhere('id', $wizard->sip_gateway_id);
        if ($gateway === null && $wizard->sip_gateway_id !== null) {
            $wizard->update(['sip_gateway_id' => null, 'sip_number_id' => null]);
        }
        $numbers = $gateway
            ? $tenant->sipNumbers()->where('provider_gateway_id', $gateway->id)
                ->with('inboundRoute.destination')->orderByDesc('id')->get()
            : collect();
        $number = $numbers->firstWhere('id', $wizard->sip_number_id);
        if ($number === null && $wizard->sip_number_id !== null) {
            $wizard->update(['sip_number_id' => null]);
        }
        $route = $number?->inboundRoute;
        [$participants, $destinationsValid] = $this->participants($route);
        $answerReady = $route?->enabled && $route->destination !== null && $destinationsValid && $participants->isNotEmpty()
            && match ($wizard->answer_type) {
                LineSetupWizard::ANSWER_PERSON => $route->destination_type === InboundRoute::DESTINATION_EXTENSION
                    && $route->destination->enabled,
                LineSetupWizard::ANSWER_TEAM => config('voip.queues_enabled')
                    && $route->destination_type === InboundRoute::DESTINATION_QUEUE
                    && $route->destination->enabled,
                LineSetupWizard::ANSWER_MENU => $route->destination_type === InboundRoute::DESTINATION_IVR
                    && $route->destination->enabled && $route->destination->published_config !== null,
                default => false,
            };
        $providerApproved = $gateway?->verification_status === SipGateway::STATUS_APPROVED && $gateway->enabled;
        $numberApproved = $number?->status === SipNumber::STATUS_ASSIGNED && $number->enabled;
        $outboundRoutes = $number && $gateway && $participants->isNotEmpty()
            ? OutboundRoute::query()->where('tenant_id', $tenant->id)
                ->where('sip_number_id', $number->id)->where('gateway_id', $gateway->id)
                ->where('enabled', true)->whereIn('sip_extension_id', $participants->pluck('id'))
                ->get()->keyBy('sip_extension_id')
            : collect();
        $outboundReady = $answerReady && $number?->outbound_enabled
            && $participants->every(fn ($extension) => $outboundRoutes->has($extension->id));

        return view('customer.setup.wizard', [
            'wizard' => $wizard,
            'gateways' => $gateways,
            'gateway' => $gateway,
            'numbers' => $numbers,
            'number' => $number,
            'route' => $route,
            'answerReady' => (bool) $answerReady,
            'participants' => $participants,
            'outboundRoutes' => $outboundRoutes,
            'outboundReady' => (bool) $outboundReady,
            'providerApproved' => $providerApproved,
            'numberApproved' => $numberApproved,
            'menus' => IvrMenu::query()->whereBelongsTo($tenant)->orderByDesc('id')->get(),
            'queues' => config('voip.queues_enabled')
                ? CallQueue::query()->whereBelongsTo($tenant)->withCount('members')->orderByDesc('id')->get()
                : collect(),
            'extensions' => $tenant->sipExtensions()->where('enabled', true)->orderBy('display_name')->get(),
        ]);
    }

    public function chooseAnswer(Request $request): RedirectResponse
    {
        $this->authorizeWizard($request);
        $answer = $request->validate(['answer_type' => ['required', Rule::in([
            LineSetupWizard::ANSWER_PERSON, LineSetupWizard::ANSWER_TEAM, LineSetupWizard::ANSWER_MENU,
        ])]])['answer_type'];
        if ($answer === LineSetupWizard::ANSWER_TEAM && ! config('voip.queues_enabled')) {
            return back()->withErrors(['answer_type' => 'تیم پاسخ‌گویی هنوز فعال نیست.']);
        }
        $wizard = $this->wizard($request);
        $wizard->update(['answer_type' => $answer]);

        return redirect()->route('customer.setup.wizard');
    }

    public function chooseGateway(Request $request): RedirectResponse
    {
        $this->authorizeWizard($request);
        $tenant = $this->tenants->forUser($request->user());
        $data = $request->validate(['gateway_id' => ['required', 'integer', Rule::exists('sip_gateways', 'id')->where('tenant_id', $tenant->id)]]);
        $gateway = $tenant->sipGateways()->findOrFail($data['gateway_id']);
        $wizard = $this->wizard($request);
        $wizard->update([
            'sip_gateway_id' => $gateway->id,
            'sip_number_id' => $wizard->sip_gateway_id === $gateway->id ? $wizard->sip_number_id : null,
        ]);

        return redirect()->route('customer.setup.wizard');
    }

    public function chooseNumber(Request $request): RedirectResponse
    {
        $this->authorizeWizard($request);
        $wizard = $this->wizard($request);
        $tenant = $this->tenants->forUser($request->user());
        $data = $request->validate(['number_id' => ['required', 'integer', Rule::exists('sip_numbers', 'id')
            ->where('tenant_id', $tenant->id)->where('provider_gateway_id', $wizard->sip_gateway_id)]]);
        $wizard->update(['sip_number_id' => $data['number_id']]);

        return redirect()->route('customer.setup.wizard');
    }

    public function createPhone(Request $request): RedirectResponse
    {
        $this->authorizeWizard($request);
        $wizard = $this->wizard($request);
        abort_unless(in_array($wizard->answer_type, [LineSetupWizard::ANSWER_TEAM, LineSetupWizard::ANSWER_MENU], true), 404);
        $tenant = $this->tenants->forUser($request->user());
        $number = $tenant->sipNumbers()->with('providerGateway')->findOrFail($wizard->sip_number_id);
        abort_unless($number->provider_gateway_id === $wizard->sip_gateway_id
            && $number->enabled && $number->outbound_enabled
            && in_array($number->status, [SipNumber::STATUS_PENDING, SipNumber::STATUS_ASSIGNED], true)
            && $number->providerGateway?->verification_status !== SipGateway::STATUS_REJECTED, 404);
        $name = $request->validate(['display_name' => ['required', 'string', 'max:100']])['display_name'];
        $result = $this->setup->createPhone($tenant, $name);

        return redirect()->route('customer.setup.phone', ['extension' => $result['extension']->id, 'wizard' => 1])
            ->with('phone_credentials', [
                'extension' => $result['extension']->extension,
                'password' => $result['password'],
                'host' => (string) config('voip.sip_host'),
                'port' => (int) config('voip.sip_port'),
            ])->with('status', 'تلفن ساخته شد. رمز را اکنون در جای امن نگه دارید.');
    }

    public function setOutbound(Request $request, int $extension): RedirectResponse
    {
        $this->authorizeWizard($request);
        $tenant = $this->tenants->forUser($request->user());
        $wizard = $this->wizard($request);
        $number = $tenant->sipNumbers()->with(['providerGateway', 'inboundRoute.destination'])->findOrFail($wizard->sip_number_id);
        abort_unless($number->provider_gateway_id === $wizard->sip_gateway_id
            && $number->enabled && $number->outbound_enabled
            && in_array($number->status, [SipNumber::STATUS_PENDING, SipNumber::STATUS_ASSIGNED], true)
            && $number->providerGateway?->verification_status !== SipGateway::STATUS_REJECTED, 404);
        $phone = $tenant->sipExtensions()->where('enabled', true)->findOrFail($extension);
        [$participants, $valid] = $this->participants($number->inboundRoute);
        abort_unless($valid && $participants->contains('id', $phone->id), 404);
        abort_unless($number->providerGateway?->tenant_id === $tenant->id, 404);

        OutboundRoute::query()->updateOrCreate(
            ['sip_extension_id' => $phone->id],
            ['tenant_id' => $tenant->id, 'sip_number_id' => $number->id,
                'gateway_id' => $number->provider_gateway_id, 'enabled' => true],
        );

        return redirect()->route('customer.setup.wizard')->with('status', 'شماره تماس خروجی این تلفن تنظیم شد.');
    }

    /** @return array{Collection<int, SipExtension>, bool} */
    private function participants(?InboundRoute $route): array
    {
        if ($route === null || $route->destination === null) {
            return [collect(), false];
        }
        if ($route->destination_type === InboundRoute::DESTINATION_EXTENSION) {
            return [collect([$route->destination]), (bool) $route->destination->enabled];
        }
        if ($route->destination_type === InboundRoute::DESTINATION_QUEUE) {
            return [$route->destination->members()->where('sip_extensions.enabled', true)->get(),
                (bool) config('voip.queues_enabled') && (bool) $route->destination->enabled];
        }
        if ($route->destination_type !== InboundRoute::DESTINATION_IVR
            || ! $route->destination->isPublished()) {
            return [collect(), false];
        }
        $config = $route->destination->published_config;
        $choices = array_map(fn ($choice) => $choice['destination'] ?? '', $config['choices'] ?? []);
        $choices[] = $config['fallback'] ?? '';
        $phones = collect();
        foreach ($choices as $choice) {
            $destination = $this->ivrMenus->destination($route->destination, (string) $choice);
            if ($destination instanceof SipExtension) {
                $phones->push($destination);
            } elseif ($destination instanceof CallQueue) {
                $phones = $phones->concat($destination->members()->where('sip_extensions.enabled', true)->get());
            } else {
                return [collect(), false];
            }
        }

        return [$phones->unique('id')->values(), true];
    }

    private function wizard(Request $request): LineSetupWizard
    {
        return LineSetupWizard::query()->firstOrCreate(
            ['tenant_id' => $this->tenants->forUser($request->user())->id,
                'created_by_user_id' => $request->user()->id],
        );
    }

    private function authorizeWizard(Request $request): void
    {
        abort_unless(! $request->user()->isAdmin()
            && $request->user()->hasPermission(Permissions::PROVIDERS_MANAGE)
            && $request->user()->hasPermission(Permissions::NUMBERS_MANAGE)
            && $request->user()->hasPermission(Permissions::PHONES_MANAGE)
            && $request->user()->hasPermission(Permissions::LINES_VIEW), 403);
    }
}
