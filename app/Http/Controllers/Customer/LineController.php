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
use App\Services\InboundAnnouncementService;
use App\Services\InboundRoutingService;
use App\Services\InboundScheduleService;
use App\Services\IvrMenuService;
use App\Services\LineUnansweredService;
use App\Services\NumberNormalizer;
use App\Services\TenantService;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
        $request->validate(['extension_id' => ['nullable', 'integer']]);
        $selectedExtension = $request->filled('extension_id') ? $tenant->sipExtensions()->where('enabled', true)->findOrFail($request->integer('extension_id')) : null;

        return response()->view('customer.lines.show', [
            'selectedExtension' => $selectedExtension,
            'number' => $line->load('inboundRoute.destination'), 'extensions' => $tenant->sipExtensions()->orderBy('extension')->get(),
            'queues' => config('voip.queues_enabled') ? CallQueue::query()->whereBelongsTo($tenant)->with('members')->where('enabled', true)->whereHas('members', fn ($q) => $q->where('enabled', true))->get() : collect(),
            'menus' => IvrMenu::query()->whereBelongsTo($tenant)->where('enabled', true)->whereNotNull('published_config')->get(),
            'outboundEnabled' => $line->outboundRoutes()->where('enabled', true)->exists(),
            'credentials' => $canManage ? session('phone_credentials') : null,
            'serviceReady' => $this->entitlements->allows($line), 'serviceMessage' => $this->entitlements->allows($line, false)
                ? ($this->entitlements->annualTerm($line) ? 'شماره آماده است؛ پاسخ‌گوی خط را تنظیم کنید.' : 'پاسخ‌گوی خط را ذخیره کنید تا خط فعال و ماه اول اشتراک شروع شود.')
                : 'اشتراک خط فعال نیست؛ برای پرداخت یا بررسی وضعیت به بخش صورتحساب بروید.',
            'limits' => $this->entitlements->limits($tenant), 'subscription' => $this->entitlements->annualTerm($line) ?? $this->entitlements->subscription($line),
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
            'strategy' => ['nullable', Rule::in(array_keys(CallQueue::STRATEGIES))],
            'fallback_extension_id' => ['nullable', 'integer', Rule::exists('sip_extensions', 'id')->where('tenant_id', $tenant->id)->where('enabled', true)],
            'direct_dial_enabled' => ['sometimes', 'boolean'],
            'direct_dial_extension_ids' => ['nullable', 'array', 'max:500'],
            'direct_dial_extension_ids.*' => ['integer', 'distinct'],
            'member_ids' => ['required_if:answerer,create_team', 'array', 'min:1'], 'member_ids.*' => ['integer', 'distinct'],
            'max_wait_seconds' => ['nullable', 'integer', 'min:15', 'max:600'],
            'menu_name' => ['required_if:answerer,create_menu', 'nullable', 'string', 'max:100'],
            'greeting' => ['required_if:answerer,create_menu', 'file', 'max:10240', 'mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp4,audio/x-m4a,audio/webm,video/webm,video/3gpp,application/octet-stream'],
            'choices' => ['required_if:answerer,create_menu', 'array'], 'choices.*.label' => ['nullable', 'string', 'max:60'],
            'choices.*.destination' => ['nullable', 'string', 'regex:/^(extension|queue|ivr):[1-9][0-9]*$/'],
            'fallback' => ['required_if:answerer,create_menu', 'nullable', 'string', 'regex:/^(extension|queue|ivr):[1-9][0-9]*$/'],
        ]);
        if (array_diff(array_keys($data['choices'] ?? []), range(0, 9))) {
            throw ValidationException::withMessages(['choices' => 'کلیدهای منو باید بین ۰ تا ۹ باشند.']);
        }
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
                    'strategy' => $data['strategy'] ?? 'ring-all', 'fallback_extension_id' => $data['fallback_extension_id'] ?? null, 'max_wait_seconds' => $data['max_wait_seconds'] ?? 60, 'enabled' => true]);
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

        return $this->saved($line, $result, ($result['password'] ?? null)
            ? 'داخلی ساخته شد؛ تلفن را با راهنمای زیر وصل کنید، سپس به تنظیم خط برگردید.'
            : 'مسیر تماس ذخیره شد. ساعت کاری و حالت بی‌پاسخ را بررسی کنید و یک تماس آزمایشی بگیرید.');
    }

    public function hours(Request $request, int $number, InboundRoutingService $routing, InboundAnnouncementService $announcements)
    {
        [$tenant, $line] = $this->line($request, $number);
        abort_if($line->inboundRoute === null, 422, 'ابتدا مسیر تماس را ذخیره کنید.');
        $data = $request->validate(array_replace(InboundRoutingService::scheduleRules(), ['schedule_mode' => ['required', 'in:anytime,scheduled']]));
        if ($data['schedule_mode'] === 'scheduled') {
            app(InboundScheduleService::class)->fromInput($data);
            if (! in_array($data['closed_action'], ['announcement', 'disconnect'], true)) {
                $routing->destination($tenant, $data['closed_action'], 'closed_action');
            }
            if ($data['closed_action'] === 'announcement' && $request->hasFile('announcement')) {
                $data['announcement_path'] = $announcements->store($line, $request->file('announcement'));
            }
        }
        DB::transaction(function () use ($tenant, $line, $routing, $data) {
            Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $locked = $tenant->sipNumbers()->lockForUpdate()->findOrFail($line->id);
            $this->entitlements->assertConfigure($locked);
            $route = $locked->inboundRoute()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $route->update($routing->scheduleSettings($tenant, $locked, $data) + ['temporary_closed_until' => null]);
        }, 3);

        return $this->saved($line, [], 'ساعت کاری و رفتار خارج از ساعت کاری ذخیره شد.');
    }

    public function unanswered(Request $request, int $number, InboundRoutingService $routing)
    {
        [$tenant, $line] = $this->line($request, $number);
        $data = $request->validate(['ring_seconds' => ['required', 'integer', 'between:10,120'], 'unanswered' => ['required', 'string', 'max:40'], 'forward_phone' => ['required_if:unanswered,forward', 'nullable', 'string', 'max:30']]);
        $type = null;
        $id = null;
        if (! in_array($data['unanswered'], ['disconnect', 'forward', 'voicemail'], true)) {
            $target = $routing->destination($tenant, $data['unanswered'], 'unanswered');
            [$type] = explode(':', $data['unanswered']);
            $id = $target->id;
        }
        DB::transaction(function () use ($request, $tenant, $line, $data, $type, $id) {
            Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $this->entitlements->assertConfigure($line->fresh());
            $route = $line->inboundRoute()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            if ($route->destination_type === $type && $route->destination_id === $id) {
                throw ValidationException::withMessages(['unanswered' => 'مقصد جایگزین باید با پاسخ‌گوی اصلی متفاوت باشد.']);
            }
            $forwardPhone = null;
            if ($data['unanswered'] === 'forward') {
                abort_unless($request->user('customer')->hasPermission(Permissions::OUTBOUND_MANAGE), 403);
                $forwardPhone = app(NumberNormalizer::class)->normalize($data['forward_phone']);
                if (! $forwardPhone) {
                    throw ValidationException::withMessages(['forward_phone' => 'شماره موبایل یا تلفن ثابت معتبر وارد کنید.']);
                }
                app(LineUnansweredService::class)->forwardingRoute($route, $forwardPhone);
                $type = 'forward';
            } elseif ($data['unanswered'] === 'voicemail') {
                abort_unless(config('voip.voicemail_enabled'), 404);
                abort_unless($route->destination_type === 'extension', 422);
                $mailbox = $tenant->sipExtensions()->where('enabled', true)->findOrFail($route->destination_id);
                if (! $mailbox->voicemail_secret_encrypted) {
                    $mailbox->update(['voicemail_secret_encrypted' => (string) random_int(100000000000, 999999999999)]);
                }
                $type = 'voicemail';
            }
            $route->update(['ring_seconds' => $data['ring_seconds'], 'unanswered_type' => $type, 'unanswered_id' => $id, 'unanswered_phone' => $forwardPhone]);
        }, 3);
        Log::info('Customer unanswered routing changed', ['tenant_id' => $tenant->id, 'sip_number_id' => $line->id]);

        return $this->saved($line, [], 'رفتار تماس‌های بی‌پاسخ ذخیره شد.');
    }

    public function temporaryClosure(Request $request, int $number)
    {
        [$tenant, $line] = $this->line($request, $number);
        $data = $request->validate(['closed' => ['required', 'boolean'], 'until' => ['required_if:closed,1', 'nullable', 'date_format:Y-m-d\TH:i'], 'timezone' => ['required_if:closed,1', 'nullable', 'timezone'], 'closed_action' => ['nullable', 'string', 'max:40']]);
        $until = $data['closed'] ? CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['until'], $data['timezone'])->utc() : null;
        if ($until && ($until->isPast() || $until->gt(now()->addDays(30)))) {
            throw ValidationException::withMessages(['until' => 'پایان تعطیلی را از اکنون تا ۳۰ روز آینده انتخاب کنید.']);
        }
        $route = $line->inboundRoute;
        abort_if($route === null || $route->tenant_id !== $tenant->id, 422);
        if ($until && ! $route->closed_destination_type) {
            $choice = $data['closed_action'] ?? 'disconnect';
            $type = 'disconnect';
            $id = null;
            if ($choice !== 'disconnect') {
                $target = app(InboundRoutingService::class)->destination($tenant, $choice, 'closed_action');
                [$type] = explode(':', $choice);
                $id = $target->id;
            }
            $route->fill(['closed_destination_type' => $type, 'closed_destination_id' => $id]);
        }
        $route->update(['temporary_closed_until' => $until]);
        Log::info('Customer temporary closure changed', ['tenant_id' => $tenant->id, 'sip_number_id' => $line->id]);

        return $this->saved($line, [], $until ? 'تعطیلی موقت فعال شد؛ در زمان انتخاب‌شده خودکار پایان می‌یابد.' : 'تعطیلی موقت لغو شد.');
    }

    public function preview(Request $request, int $number, InboundScheduleService $schedules)
    {
        [, $line] = $this->line($request, $number, false);
        $data = $request->validate(['at' => ['required', 'date_format:Y-m-d\TH:i']]);
        $route = $line->inboundRoute;
        abort_if($route === null, 404);
        $at = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['at'], $route->schedule['timezone'] ?? 'Asia/Tehran');
        $open = $schedules->isOpen($route, $at);
        $destination = ! $route->enabled || ! $line->enabled || ! $line->inbound_enabled
            ? 'تماس ورودی این خط غیرفعال است'
            : ($open ? $route->destinationLabel() : $route->closedDestinationLabel());

        return back()->with('schedule_preview', ['at' => $at->format('Y-m-d H:i'), 'open' => $open,
            'destination' => $destination]);
    }

    public function announcement(Request $request, int $number)
    {
        [, $line] = $this->line($request, $number, false);
        $path = $line->inboundRoute?->closed_announcement_path;
        abort_unless(is_string($path) && preg_match('#^announcements/'.$line->tenant_id.'/'.$line->id.'/[0-9A-Z]+\.wav$#D', $path) && Storage::disk('ivr')->exists($path), 404);

        return response()->file(Storage::disk('ivr')->path($path), ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, no-store']);
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
        $data = $request->validate(['enabled' => ['required', 'boolean'], 'display_name' => ['sometimes', 'string', 'max:100'], 'max_registrations' => ['sometimes', 'integer', 'between:1,5']]);
        DB::transaction(function () use ($tenant, $extension, $data) {
            Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $phone = $tenant->sipExtensions()->lockForUpdate()->findOrFail($extension);
            if ($data['enabled'] && ! $phone->enabled) {
                $this->entitlements->assertCapacity($tenant, 'extensions');
            }
            $phone->update($data);
            Log::info('Customer phone settings updated', ['tenant_id' => $tenant->id, 'extension_id' => $phone->id, 'max_registrations' => $phone->max_registrations]);
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
            $url = route('customer.extensions.show', ['extension' => $result['extension']->id, 'number_id' => $number->id]);
        }
        $response = redirect()->to($url)->with('status', $message);
        if (($result['password'] ?? null) !== null) {
            $response->with('phone_credentials', ['extension' => $result['extension']->extension, 'sip_username' => $result['extension']->sipIdentity(), 'password' => $result['password'],
                'host' => config('voip.sip_host'), 'port' => config('voip.sip_port')]);
        }

        return $response->header('Cache-Control', 'private, no-store');
    }
}
