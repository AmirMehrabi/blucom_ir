<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Models\Tenant;
use App\Services\Commerce\LineEntitlementService;
use App\Services\CustomerLineSetupService;
use App\Services\LiveOverviewService;
use App\Services\TenantService;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ExtensionController extends Controller
{
    public function __construct(private TenantService $tenants, private CustomerLineSetupService $setup, private LineEntitlementService $entitlements) {}

    public function index(Request $request)
    {
        $tenant = $this->tenants->forUser($request->user('customer'));
        $request->validate(['number_id' => ['nullable', 'integer']]);
        $number = $request->filled('number_id') ? $tenant->sipNumbers()->where('status', 'assigned')->findOrFail($request->integer('number_id')) : null;
        $live = $request->user('customer')->hasPermission(Permissions::LIVE_VIEW)
            ? collect(app(LiveOverviewService::class)->snapshot($request->user('customer'), $tenant)['extensions'])->keyBy('id') : collect();

        return response()->view('customer.extensions.index', [
            'extensions' => $tenant->sipExtensions()->with('outboundRoute.sipNumber')->orderBy('extension')->get(),
            'suggestedExtension' => $this->setup->suggestedExtension(), 'setupNumber' => $number, 'live' => $live,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $tenant = $this->tenants->forUser($request->user('customer'));
        $data = $request->validate(['display_name' => ['required', 'string', 'max:100'], 'extension' => ['required', 'string', 'max:9'], 'number_id' => ['nullable', 'integer']]);
        $number = ! empty($data['number_id']) ? $tenant->sipNumbers()->where('status', 'assigned')->findOrFail($data['number_id']) : null;
        $result = $this->setup->createPhone($tenant, $data['display_name'], extensionNumber: $data['extension']);

        return $this->saved($result['extension'], $result['password'], $number?->id);
    }

    public function show(Request $request, int $extension)
    {
        $tenant = $this->tenants->forUser($request->user('customer'));
        $phone = $tenant->sipExtensions()->with('outboundRoute.sipNumber')->findOrFail($extension);
        $request->validate(['number_id' => ['nullable', 'integer']]);
        $number = $request->filled('number_id') ? $tenant->sipNumbers()->where('status', 'assigned')->findOrFail($request->integer('number_id')) : null;
        $menus = IvrMenu::query()->whereBelongsTo($tenant)->get()->filter(function ($menu) use ($phone) {
            $config = $menu->published_config ?? [];

            return ($config['fallback'] ?? '') === 'extension:'.$phone->id
                || collect($config['choices'] ?? [])->contains(fn ($choice) => ($choice['destination'] ?? '') === 'extension:'.$phone->id);
        });

        return response()->view('customer.extensions.show', [
            'phone' => $phone, 'extensions' => collect([$phone]), 'credentials' => session('phone_credentials'),
            'canManage' => true, 'route' => null, 'extensionWorkspace' => true, 'setupNumber' => $number,
            'numbers' => $tenant->sipNumbers()->where('status', 'assigned')->get(),
            'inboundRoutes' => InboundRoute::query()->whereBelongsTo($tenant)->where(function ($query) use ($phone) {
                $query->where(fn ($q) => $q->where('destination_type', 'extension')->where('destination_id', $phone->id))
                    ->orWhere(fn ($q) => $q->where('closed_destination_type', 'extension')->where('closed_destination_id', $phone->id));
            })->with('sipNumber')->get(),
            'teams' => CallQueue::query()->whereBelongsTo($tenant)->whereHas('members', fn ($q) => $q->where('sip_extensions.id', $phone->id))->get(), 'menus' => $menus,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function update(Request $request, int $extension)
    {
        $tenant = $this->tenants->forUser($request->user('customer'));
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'display_name' => ['sometimes', 'required', 'string', 'max:100'], 'max_registrations' => ['sometimes', 'required', 'integer', 'between:1,5']]);
        $phone = DB::transaction(function () use ($tenant, $extension, $data) {
            Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $phone = $tenant->sipExtensions()->lockForUpdate()->findOrFail($extension);
            if ($data['enabled'] && ! $phone->enabled) {
                $this->entitlements->assertCapacity($tenant, 'extensions');
            }
            $phone->update($data);
            Log::info('Customer extension updated', ['tenant_id' => $tenant->id, 'extension_id' => $phone->id]);

            return $phone;
        }, 3);

        return $this->saved($phone);
    }

    public function reset(Request $request, int $extension)
    {
        $tenant = $this->tenants->forUser($request->user('customer'));
        $phone = $tenant->sipExtensions()->findOrFail($extension);
        $password = Str::random(24);
        $phone->update(['password_encrypted' => $password]);
        Log::info('Customer extension credentials reset', ['tenant_id' => $tenant->id, 'extension_id' => $phone->id]);

        return $this->saved($phone, $password);
    }

    private function saved(SipExtension $phone, ?string $password = null, ?int $number = null)
    {
        $response = redirect()->route('customer.extensions.show', ['extension' => $phone->id] + ($number ? ['number_id' => $number] : []))
            ->with('status', $password ? 'داخلی آماده است؛ رمز را در جای امن نگه دارید و تلفن را وصل کنید.' : 'تنظیمات داخلی ذخیره شد.');
        if ($password !== null) {
            $response->with('phone_credentials', ['extension' => $phone->extension, 'sip_username' => $phone->sipIdentity(), 'password' => $password]);
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
