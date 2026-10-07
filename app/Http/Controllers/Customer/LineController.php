<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\CommerceOrder;
use App\Models\IvrMenu;
use App\Models\OutboundRoute;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\Commerce\LineEntitlementService;
use App\Services\CustomerLineSetupService;
use App\Services\IvrMenuService;
use App\Services\TenantService;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class LineController extends Controller
{
    public function __construct(private TenantService $tenants, private CustomerLineSetupService $setup, private LineEntitlementService $entitlements) {}

    public function index(Request $request)
    {
        $tenant = $this->tenants->forUser($request->user('customer'));

        return response()->view('customer.lines.index', [
            'numbers' => $tenant->sipNumbers()->where('status', 'assigned')->with(['inboundRoute.destination', 'providerGateway'])->get(),
            'pendingOrders' => CommerceOrder::query()->where('tenant_id', $tenant->id)->whereIn('status', ['paid_pending_allocation', 'paid_unfulfilled'])->get(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, int $number)
    {
        [$tenant, $line] = $this->line($request, $number, false);
        $canManage = $request->user('customer')->hasPermission(Permissions::PHONES_MANAGE);

        return response()->view('customer.lines.show', [
            'number' => $line->load('inboundRoute.destination'), 'extensions' => $tenant->sipExtensions()->orderBy('extension')->get(),
            'queues' => CallQueue::query()->whereBelongsTo($tenant)->with('members')->where('enabled', true)->get(),
            'menus' => IvrMenu::query()->whereBelongsTo($tenant)->where('enabled', true)->whereNotNull('published_config')->get(),
            'outboundEnabled' => $line->outboundRoutes()->where('enabled', true)->exists(),
            'credentials' => $canManage ? session('phone_credentials') : null,
            'serviceReady' => $this->entitlements->allows($line), 'serviceMessage' => $this->entitlements->allows($line, false) ? 'پاسخ‌گوی خط را ذخیره کنید تا خط فعال و ماه اول اشتراک شروع شود.' : 'اشتراک خط فعال نیست؛ برای پیگیری با پشتیبانی تماس بگیرید.',
            'limits' => $this->entitlements->limits($tenant), 'subscription' => $this->entitlements->subscription($line),
            'canManage' => $canManage && $this->entitlements->allows($line, false),
            'suggestedExtension' => $canManage ? $this->setup->suggestedExtension() : null,
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function answer(Request $request, int $number)
    {
        [$tenant, $line] = $this->line($request, $number);
        $data = $request->validate([
            'answerer' => ['required', Rule::in(['new', 'existing', 'team', 'menu', 'create_team', 'create_menu'])],
            'display_name' => ['required_if:answerer,new', 'nullable', 'string', 'max:100'],
            'extension' => ['nullable', 'string', 'max:9'],
            'extension_id' => ['required_if:answerer,existing', 'nullable', 'integer'],
            'queue_id' => ['required_if:answerer,team', 'nullable', 'integer'],
            'menu_id' => ['required_if:answerer,menu', 'nullable', 'integer'],
            'team_name' => ['required_if:answerer,create_team', 'nullable', 'string', 'max:100'],
            'member_ids' => ['required_if:answerer,create_team', 'array', 'min:1'], 'member_ids.*' => ['integer', 'distinct'],
            'max_wait_seconds' => ['nullable', 'integer', 'min:10', 'max:600'],
            'menu_name' => ['required_if:answerer,create_menu', 'nullable', 'string', 'max:100'],
            'greeting' => ['required_if:answerer,create_menu', 'file', 'max:10240', 'mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp4,audio/x-m4a,audio/webm,video/webm,application/octet-stream'],
            'choices' => ['required_if:answerer,create_menu', 'array'], 'choices.*.label' => ['nullable', 'string', 'max:60'],
            'choices.*.destination' => ['nullable', 'string', 'regex:/^(extension|queue):[1-9][0-9]*$/'],
            'fallback' => ['required_if:answerer,create_menu', 'nullable', 'string', 'regex:/^(extension|queue):[1-9][0-9]*$/'],
        ]);
        $result = DB::transaction(function () use ($request, $tenant, $line, $data) {
            Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $line = SipNumber::query()->lockForUpdate()->findOrFail($line->id);
            abort_unless($line->tenant_id === $tenant->id, 404);
            $this->entitlements->assertConfigure($line);
            if ($data['answerer'] === 'create_team') {
                abort_unless(config('voip.queues_enabled'), 404);
                $this->entitlements->assertCapacity($tenant, 'queues');
                $members = $tenant->sipExtensions()->where('enabled', true)->whereIn('id', $data['member_ids'])->get();
                abort_unless($members->count() === count($data['member_ids']), 422);
                $queue = CallQueue::query()->create(['tenant_id' => $tenant->id, 'name' => $data['team_name'],
                    'strategy' => 'ring-all', 'max_wait_seconds' => $data['max_wait_seconds'] ?? 60, 'enabled' => true]);
                $queue->members()->sync($members->modelKeys());
                $data = [...$data, 'answerer' => 'team', 'queue_id' => $queue->id];
            } elseif ($data['answerer'] === 'create_menu') {
                $this->entitlements->assertCapacity($tenant, 'ivr_menus');
                $menu = IvrMenu::query()->create(['tenant_id' => $tenant->id, 'name' => $data['menu_name'], 'enabled' => true]);
                $service = app(IvrMenuService::class);
                $service->saveDraft($menu, [...$data, 'name' => $data['menu_name']], $request->file('greeting'));
                $service->publish($menu);
                $data = [...$data, 'answerer' => 'menu', 'menu_id' => $menu->id];
            }
            $result = $this->setup->setAnswerer($tenant, $line, [...$data, 'configure_outbound' => false]);
            $this->entitlements->activate($line);

            return $result;
        }, 3);

        return $this->saved($line, $result, 'پاسخ‌گویی خط ذخیره شد و خط آماده تماس است.');
    }

    public function phones(Request $request, int $number)
    {
        [$tenant, $line] = $this->line($request, $number);
        $data = $request->validate(['display_name' => ['required', 'string', 'max:100'], 'extension' => ['nullable', 'string', 'max:9']]);
        $result = DB::transaction(function () use ($tenant, $line, $data) {
            Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $this->entitlements->assertConfigure(SipNumber::query()->lockForUpdate()->findOrFail($line->id));

            return $this->setup->createPhone($tenant, $data['display_name'], extensionNumber: $data['extension'] ?? null);
        }, 3);

        return $this->saved($line, $result, 'تلفن ساخته شد؛ دستگاه خود را انتخاب کنید و اطلاعات اتصال را وارد کنید.');
    }

    public function outbound(Request $request, int $number)
    {
        [$tenant, $line] = $this->line($request, $number);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'extension_ids' => ['required_if:enabled,1', 'array'], 'extension_ids.*' => ['integer', 'distinct']]);
        DB::transaction(function () use ($tenant, $line, $data) {
            Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $line = SipNumber::query()->lockForUpdate()->findOrFail($line->id);
            $this->entitlements->assertConfigure($line);
            $ids = $data['extension_ids'] ?? [];
            abort_unless($tenant->sipExtensions()->where('enabled', true)->whereIn('id', $ids)->count() === count($ids), 422);
            $line->outboundRoutes()->where('tenant_id', $tenant->id)->update(['enabled' => false]);
            if ($data['enabled']) {
                foreach ($ids as $id) {
                    OutboundRoute::query()->updateOrCreate(['sip_extension_id' => $id], [
                        'tenant_id' => $tenant->id, 'sip_number_id' => $line->id, 'gateway_id' => $line->provider_gateway_id, 'enabled' => true,
                    ]);
                }
            }
            Log::info('Customer outbound routing changed', ['tenant_id' => $tenant->id, 'sip_number_id' => $line->id, 'extension_ids' => $ids]);
        }, 3);

        return $this->saved($line, [], 'تنظیم تماس خروجی ذخیره شد.');
    }

    public function updatePhone(Request $request, int $number, int $extension)
    {
        [$tenant, $line] = $this->line($request, $number);
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'display_name' => ['sometimes', 'string', 'max:100']]);
        DB::transaction(function () use ($tenant, $extension, $data) {
            Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $phone = $tenant->sipExtensions()->lockForUpdate()->findOrFail($extension);
            if ($data['enabled'] && ! $phone->enabled) {
                $this->entitlements->assertCapacity($tenant, 'extensions');
            }
            $phone->update($data);
        }, 3);

        return $this->saved($line, [], 'تلفن به‌روز شد.');
    }

    public function resetPhone(Request $request, int $number, int $extension)
    {
        [$tenant, $line] = $this->line($request, $number);
        $phone = $tenant->sipExtensions()->findOrFail($extension);
        $password = Str::random(24);
        $phone->update(['password_encrypted' => $password]);
        Log::info('Customer phone credentials reset', ['tenant_id' => $tenant->id, 'extension_id' => $phone->id]);

        return $this->saved($line, ['extension' => $phone, 'password' => $password], 'رمز جدید ساخته شد؛ تلفن را با رمز جدید تنظیم کنید.');
    }

    private function line(Request $request, int $id, bool $configure = true): array
    {
        $actor = $request->user('customer');
        $tenant = $this->tenants->forUser($actor);
        if ($configure) {
            abort_unless($actor->hasPermission(Permissions::PHONES_MANAGE), 403);
        }
        $number = $tenant->sipNumbers()->where('status', 'assigned')->findOrFail($id);
        if ($configure) {
            $this->entitlements->assertConfigure($number);
        }

        return [$tenant, $number];
    }

    private function saved(SipNumber $number, array $result, string $message)
    {
        $url = route('customer.lines.show', $number->id);
        if (($result['password'] ?? null) !== null) {
            $url .= '#phones';
        }
        $response = redirect()->to($url)->with('status', $message);
        if (($result['password'] ?? null) !== null) {
            $response->with('phone_credentials', ['extension' => $result['extension']->extension, 'password' => $result['password'],
                'host' => config('voip.sip_host'), 'port' => config('voip.sip_port')]);
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
