<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\CustomerLineSetupService;
use App\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class SetupController extends Controller
{
    public function __construct(
        private readonly TenantService $tenants,
        private readonly CustomerLineSetupService $setup,
    ) {}

    public function provider(Request $request): View
    {
        $tenant = $this->tenant($request);
        $editing = null;
        if ($request->filled('edit')) {
            $editing = $tenant->sipGateways()
                ->whereIn('verification_status', [SipGateway::STATUS_PENDING, SipGateway::STATUS_REJECTED])
                ->findOrFail($request->integer('edit'));
        }

        return view('customer.setup.provider', [
            'tenant' => $tenant,
            'gateways' => $tenant->sipGateways()->orderByDesc('id')->get(),
            'editing' => $editing,
            'step' => 1,
        ]);
    }

    public function storeProvider(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $data = $this->validatedProvider($request);

        $this->setup->addProvider($tenant, $data);

        return redirect($request->user()->hasPermission('numbers.manage') ? '/setup/number' : $request->user()->homePath())
            ->with('status', 'اطلاعات ارائه‌دهنده ذخیره شد و در انتظار بررسی است.');
    }

    public function updateProvider(Request $request, int $gateway): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $record = $tenant->sipGateways()->findOrFail($gateway);
        $this->setup->resubmitProvider($tenant, $record, $this->validatedProvider($request));

        return redirect()->route('customer.setup.provider')->with('status', 'اتصال برای بررسی دوباره فرستاده شد.');
    }

    public function number(Request $request): View|RedirectResponse
    {
        $tenant = $this->tenant($request);
        $gateways = $tenant->sipGateways()->where('verification_status', '!=', SipGateway::STATUS_REJECTED)->orderBy('display_name')->get();
        if ($gateways->isEmpty() && $request->user()->hasPermission('providers.manage')) {
            return redirect()->route('customer.setup.provider');
        }

        return view('customer.setup.number', [
            'tenant' => $tenant,
            'gateways' => $gateways,
            'numbers' => $tenant->sipNumbers()->with('providerGateway')->orderByDesc('id')->get(),
            'editing' => null,
            'step' => 2,
        ]);
    }

    public function editNumber(Request $request, int $number): View
    {
        $tenant = $this->tenant($request);
        $editing = $tenant->sipNumbers()
            ->whereIn('status', [SipNumber::STATUS_PENDING, SipNumber::STATUS_DISABLED])
            ->with('providerGateway')->findOrFail($number);

        return view('customer.setup.number', [
            'tenant' => $tenant,
            'gateways' => $tenant->sipGateways()->where('verification_status', '!=', SipGateway::STATUS_REJECTED)->orderBy('display_name')->get(),
            'numbers' => $tenant->sipNumbers()->with('providerGateway')->orderByDesc('id')->get(),
            'editing' => $editing,
            'step' => 2,
        ]);
    }

    public function storeNumber(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $data = $this->validatedNumber($request, $tenant->id);
        $gateway = $tenant->sipGateways()->where('verification_status', '!=', SipGateway::STATUS_REJECTED)->findOrFail($data['gateway_id']);
        $number = $this->setup->addNumber($tenant, $gateway, $data['number']);
        $number->update(['requested_by_user_id' => $request->user()->id]);

        return redirect($request->user()->hasPermission('phones.manage') ? route('customer.setup.answer', $number) : $request->user()->homePath())
            ->with('status', 'شماره شما ثبت شد و در انتظار تأیید است.');
    }

    public function updateNumber(Request $request, int $number): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $record = $tenant->sipNumbers()->with('providerGateway')->findOrFail($number);
        $data = $this->validatedNumber($request, $tenant->id);
        $gateway = $tenant->sipGateways()->where('verification_status', '!=', SipGateway::STATUS_REJECTED)->findOrFail($data['gateway_id']);
        $this->setup->resubmitNumber($tenant, $record, $gateway, $data['number']);
        $record->update(['requested_by_user_id' => $request->user()->id]);

        return redirect($request->user()->hasPermission('phones.manage') ? route('customer.setup.answer', $record) : $request->user()->homePath())
            ->with('status', 'شماره برای بررسی دوباره فرستاده شد.');
    }

    public function answer(Request $request, int $number): View
    {
        $tenant = $this->tenant($request);
        $sipNumber = $tenant->sipNumbers()->with(['providerGateway', 'inboundRoute.destination'])->findOrFail($number);

        return view('customer.setup.answer', [
            'tenant' => $tenant,
            'number' => $sipNumber,
            'extensions' => $tenant->sipExtensions()->where('enabled', true)->orderBy('display_name')->get(),
            'step' => 3,
        ]);
    }

    public function storeAnswer(Request $request, int $number): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $sipNumber = $tenant->sipNumbers()->with('providerGateway')->findOrFail($number);
        $data = $request->validate([
            'answerer' => ['required', Rule::in(['new', 'existing'])],
            'display_name' => ['required_if:answerer,new', 'nullable', 'string', 'max:100'],
            'extension_id' => ['required_if:answerer,existing', 'nullable', 'integer', Rule::exists('sip_extensions', 'id')->where('tenant_id', $tenant->id)],
        ]);
        $result = $this->setup->setAnswerer($tenant, $sipNumber, $data);
        $redirect = redirect()->route('customer.setup.phone', $result['extension'])->with('status', 'پاسخ‌گوی این شماره تنظیم شد.');
        if ($result['password'] !== null) {
            $redirect->with('phone_credentials', $this->credentials($result['extension'], $result['password']));
        }

        return $redirect;
    }

    public function phone(Request $request, int $extension): Response
    {
        $tenant = $this->tenant($request);
        $sipExtension = $tenant->sipExtensions()->findOrFail($extension);

        return response()->view('customer.setup.phone', [
            'tenant' => $tenant,
            'extension' => $sipExtension,
            'credentials' => session('phone_credentials'),
            'step' => 4,
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function resetPhonePassword(Request $request, int $extension): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $sipExtension = $tenant->sipExtensions()->findOrFail($extension);
        $password = Str::random(20);
        $sipExtension->update(['password_encrypted' => $password]);
        Log::info('Customer phone password regenerated', ['tenant_id' => $tenant->id, 'extension_id' => $sipExtension->id]);

        return redirect()->route('customer.setup.phone', $sipExtension)
            ->with('phone_credentials', $this->credentials($sipExtension, $password))
            ->with('status', 'رمز جدید ساخته شد. رمز قبلی دیگر معتبر نیست.');
    }

    public function lines(Request $request): View
    {
        $tenant = $this->tenant($request);

        return view('customer.setup.lines', [
            'tenant' => $tenant,
            'gateways' => $tenant->sipGateways()->orderBy('display_name')->get(),
            'numbers' => $tenant->sipNumbers()->with(['providerGateway', 'inboundRoute.destination'])->orderBy('normalized_number')->get(),
        ]);
    }

    private function tenant(Request $request): Tenant
    {
        return $this->tenants->forUser($request->user());
    }

    /** @return array<string, string|int> */
    private function credentials(SipExtension $extension, string $password): array
    {
        return [
            'extension' => $extension->extension,
            'password' => $password,
            'host' => (string) config('voip.sip_host'),
            'port' => (int) config('voip.sip_port'),
        ];
    }

    /** @return array<string, mixed> */
    private function validatedProvider(Request $request): array
    {
        return $request->validate([
            'display_name' => ['required', 'string', 'max:100'],
            'provider_name' => ['required', 'string', 'max:100'],
            'connection_method' => ['required', Rule::in(['credentials', 'ip'])],
            'host' => ['required', 'string', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'transport' => ['nullable', Rule::in(['udp', 'tcp', 'tls'])],
            'username' => ['required_if:connection_method,credentials', 'nullable', 'string', 'max:100'],
            'password' => ['required_if:connection_method,credentials', 'nullable', 'string', 'max:255'],
        ]);
    }

    /** @return array{number: string, gateway_id: int} */
    private function validatedNumber(Request $request, int $tenantId): array
    {
        return $request->validate([
            'number' => ['required', 'string', 'max:32'],
            'gateway_id' => ['required', 'integer', Rule::exists('sip_gateways', 'id')->where('tenant_id', $tenantId)],
        ]);
    }
}
