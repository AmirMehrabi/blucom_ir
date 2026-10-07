<?php

namespace Tests\Feature;

use App\Contracts\OtpProvider;
use App\Enums\CustomerRole;
use App\Enums\UserType;
use App\Http\Controllers\AuthController;
use App\Models\CallQueue;
use App\Models\CallRecord;
use App\Models\CallRecording;
use App\Models\Customer;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\User;
use App\Services\BlucomOwner;
use App\Services\CustomerAccountService;
use App\Services\CustomerOtpService;
use App\Services\FreeSwitchDialplanService;
use App\Services\FreeSwitchDirectoryService;
use App\Services\TenantService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CustomerIsolationTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'https://my.blucom.ir';

    private function owner(string $mobile = '+989120000001', string $business = 'Business A'): Customer
    {
        return app(CustomerAccountService::class)->createOwner('Owner', $mobile, $business);
    }

    public function test_admin_creates_separate_customer_businesses_and_limited_staff(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin)->post('/admin/customers', [
            'name' => 'Ali', 'mobile' => '09120000001', 'role' => 'owner', 'business' => 'Business A',
        ])->assertRedirect('/admin/customers');
        $a = Customer::query()->firstOrFail();
        $b = $this->owner('+989120000002', 'Business B');
        $this->assertNotSame($a->tenant_id, $b->tenant_id);
        $this->assertSame($a->id, $a->tenant->owner_customer_id);
        $this->assertNull($a->tenant->owner_user_id);
        $this->assertNull($a->tenant->system_key);
        $this->assertDatabaseCount('users', 1);
        $this->assertFalse($a->hasPermission(Permissions::PROVIDERS_MANAGE));
        $this->actingAs($admin)->post('/admin/customers', [
            'name' => 'Staff', 'mobile' => '09120000003', 'role' => 'staff', 'tenant_id' => $a->tenant_id,
        ])->assertRedirect();
        $staff = Customer::query()->where('role', CustomerRole::Staff)->firstOrFail();
        $this->assertTrue($staff->hasPermission(Permissions::LINES_VIEW));
        $this->assertFalse($staff->hasPermission(Permissions::PHONES_MANAGE));
        $this->assertFalse($staff->hasPermission(Permissions::BILLING_MANAGE));
        $this->actingAs($admin)->get('/admin/customers')->assertOk()->assertSee('Business A')->assertSee('Business B');
        $this->actingAs($admin)->post('/admin/customers', [
            'name' => 'Wrong staff', 'mobile' => '09120000004', 'role' => 'staff',
            'tenant_id' => app(BlucomOwner::class)->get()->id,
        ])->assertSessionHasErrors('tenant_id');
    }

    public function test_customer_portal_has_separate_login_guard_and_cookies(): void
    {
        config(['session.domain' => '.blucom.ir']);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin, 'web')->get(self::HOST.'/login')->assertOk()->assertSee('ورود مشتریان بلوکام');
        $response = $this->get(self::HOST.'/dashboard')->assertRedirect(self::HOST.'/login');
        $cookies = $response->headers->getCookies();
        $cookie = collect($cookies)->first(fn ($cookie) => $cookie->getName() === config('portal.customer_session_cookie'));
        $this->assertNotNull($cookie);
        $this->assertNull($cookie->getDomain());
        $customer = $this->owner();
        Auth::guard('web')->logout();
        $this->actingAs($customer, 'customer')->get('https://admin.blucom.ir/dashboard')->assertRedirect('https://admin.blucom.ir/login');
        $this->get(self::HOST.'/dashboard')->assertOk();
        $this->get(self::HOST.'/')->assertRedirect(self::HOST.'/dashboard');
        $this->get(self::HOST.'/auth/me')->assertOk()->assertJsonPath('customer.id', $customer->id)->assertJsonMissingPath('user');
    }

    public function test_otp_authenticates_customer_even_when_user_has_identical_id_and_mobile(): void
    {
        $customer = $this->owner();
        $admin = User::factory()->create(['user_type' => UserType::Admin, 'mobile' => $customer->mobile]);
        $this->assertSame($admin->id, $customer->id);
        $delivery = new class implements OtpProvider
        {
            public string $code = '';

            public function send(string $mobile, string $code): void
            {
                $this->code = $code;
            }
        };
        $this->app->instance(OtpProvider::class, $delivery);
        $challenge = $this->postJson(self::HOST.'/auth/otp/request', ['mobile' => $customer->mobile])->assertOk()->json('challenge_id');
        $this->assertDatabaseCount('otp_challenges', 0);
        $this->assertDatabaseHas('customer_otp_challenges', ['id' => $challenge, 'customer_id' => $customer->id]);
        $this->postJson(self::HOST.'/auth/otp/verify', [
            'mobile' => $customer->mobile, 'code' => $delivery->code, 'challenge_id' => $challenge,
        ])->assertOk()->assertJsonPath('customer.id', $customer->id)->assertJsonMissingPath('user');
        $this->assertAuthenticatedAs($customer, 'customer');
        $this->assertGuest('web');
        $this->post(self::HOST.'/auth/logout')->assertRedirect(self::HOST.'/login');
        $this->assertGuest('customer');
        $this->postJson(self::HOST.'/auth/otp/verify', [
            'mobile' => $customer->mobile, 'code' => $delivery->code, 'challenge_id' => $challenge,
        ])->assertUnprocessable();
    }

    public function test_customer_login_does_not_accept_internal_accounts_or_signup_unknown_numbers(): void
    {
        User::factory()->create(['user_type' => UserType::Admin, 'mobile' => '+989120000009']);
        $this->postJson(self::HOST.'/auth/otp/request', ['mobile' => '+989120000009'])->assertUnprocessable();
        $customer = $this->owner();
        $this->postJson('https://admin.blucom.ir/auth/otp/request', ['mobile' => $customer->mobile])->assertUnprocessable();
        $this->assertDatabaseCount('customers', 1);
        $this->assertDatabaseCount('customer_otp_challenges', 0);
        $this->assertDatabaseCount('otp_challenges', 0);
    }

    public function test_otp_is_bound_to_customer_session_and_has_attempt_expiry_and_replay_limits(): void
    {
        $customer = $this->owner();
        $this->app->instance(OtpProvider::class, new class implements OtpProvider
        {
            public function send(string $mobile, string $code): void {}
        });
        $service = app(CustomerOtpService::class);
        $challenge = $service->issue($customer);
        $this->postJson(self::HOST.'/auth/otp/verify', [
            'mobile' => $customer->mobile, 'code' => '000000', 'challenge_id' => $challenge->id,
        ])->assertUnprocessable();
        $this->assertSame(0, $challenge->fresh()->attempts);
        for ($attempt = 0; $attempt < config('auth.otp.max_attempts'); $attempt++) {
            $this->assertFalse($service->verify($challenge, '000000'));
        }
        $this->assertNotNull($challenge->fresh()->verified_at);
        $expired = $service->issue($customer);
        $expired->update(['expires_at' => now()->subSecond()]);
        $this->assertFalse($service->verify($expired, '000000'));
        $this->assertSame(0, $expired->fresh()->attempts);
    }

    public function test_customers_cannot_acquire_infrastructure_access_or_reassign_membership(): void
    {
        $customer = $this->owner();
        $customer->permissions()->create(['permission' => Permissions::PROVIDERS_MANAGE]);
        $customer->permissions()->create(['permission' => Permissions::NUMBERS_MANAGE]);
        $this->actingAs($customer, 'customer');
        foreach (['/users', '/admin/customers', '/admin/sip-numbers', '/sip-gateways', '/sip-extensions', '/outbound-routes', '/setup/provider', '/setup/number', '/setup/wizard'] as $path) {
            $response = $this->get(self::HOST.$path);
            str_starts_with($path, '/setup/') ? $response->assertForbidden() : $response->assertNotFound();
        }
        $this->post(self::HOST.'/setup/providers', ['tenant_id' => $customer->tenant_id])->assertForbidden();
        $this->post(self::HOST.'/setup/numbers', ['number' => '02191093464'])->assertForbidden();
        $this->post(self::HOST.'/internal/freeswitch/xml')->assertNotFound();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin, 'web')->put('https://admin.blucom.ir/admin/customers/'.$customer->id, [
            'name' => 'Owner', 'enabled' => 1, 'tenant_id' => app(BlucomOwner::class)->get()->id,
            'permissions' => [Permissions::PROVIDERS_MANAGE],
        ])->assertSessionHasErrors(['tenant_id', 'permissions.0']);
        $this->assertSame($customer->tenant_id, $customer->fresh()->tenant_id);
    }

    public function test_foreign_numbers_extensions_menus_queues_and_calls_are_inaccessible(): void
    {
        config(['voip.queues_enabled' => true]);
        $a = $this->owner();
        $b = $this->owner('+989120000002', 'Private business');
        $number = SipNumber::factory()->for($b->tenant)->create(['label' => 'Private number']);
        $extension = SipExtension::factory()->for($b->tenant)->create(['display_name' => 'Private phone']);
        $menu = IvrMenu::query()->create(['tenant_id' => $b->tenant_id, 'name' => 'Private menu', 'enabled' => true]);
        $queue = CallQueue::query()->create(['tenant_id' => $b->tenant_id, 'name' => 'Private queue', 'strategy' => 'ring-all', 'max_wait_seconds' => 90, 'enabled' => true]);
        $queue->members()->attach($extension);
        $call = CallRecord::query()->create(['tenant_id' => $b->tenant_id, 'freeswitch_uuid' => (string) Str::uuid(),
            'direction' => 'inbound', 'status' => 'answered', 'started_at' => now(), 'source_number' => 'Private caller']);
        $this->actingAs($a, 'customer');
        foreach (['/setup/lines', '/dashboard', '/menus', '/teams', '/calls?range=all'] as $path) {
            $this->get(self::HOST.$path)->assertOk()->assertDontSee('Private phone')->assertDontSee('Private menu')
                ->assertDontSee('Private queue')->assertDontSee('Private caller')->assertDontSee($number->number);
        }
        foreach (['/setup/answer/'.$number->id, '/setup/phone/'.$extension->id, '/menus/'.$menu->id.'/edit', '/calls/'.$call->id] as $path) {
            $this->get(self::HOST.$path)->assertNotFound();
        }
        $this->post(self::HOST.'/setup/phone/'.$extension->id.'/reset')->assertNotFound();
        $this->post(self::HOST.'/setup/answer/'.$number->id, ['answerer' => 'existing', 'extension_id' => $extension->id])->assertNotFound();
        $this->post(self::HOST.'/menus/'.$menu->id.'/publish')->assertNotFound();
        $this->put(self::HOST.'/teams/'.$queue->id, [])->assertNotFound();
        $this->delete(self::HOST.'/teams/'.$queue->id)->assertNotFound();
        $this->delete(self::HOST.'/menus/'.$menu->id)->assertNotFound();
    }

    public function test_customer_configures_owned_admin_provisioned_number_without_gateway_selection(): void
    {
        $a = $this->owner();
        $b = $this->owner('+989120000002');
        $gateway = SipGateway::factory()->create(['tenant_id' => null, 'enabled' => true, 'verification_status' => 'approved']);
        $number = SipNumber::factory()->for($a->tenant)->create(['provider_gateway_id' => $gateway->id]);
        $foreignPhone = SipExtension::factory()->for($b->tenant)->create();
        $this->actingAs($a, 'customer')->post(self::HOST.'/setup/answer/'.$number->id, [
            'answerer' => 'existing', 'extension_id' => $foreignPhone->id,
        ])->assertSessionHasErrors('extension_id');
        $this->post(self::HOST.'/setup/answer/'.$number->id, [
            'answerer' => 'new', 'display_name' => 'Own phone', 'tenant_id' => $b->tenant_id, 'gateway_id' => 9999,
        ])->assertRedirect();
        $extension = SipExtension::query()->where('tenant_id', $a->tenant_id)->firstOrFail();
        $this->assertDatabaseHas('inbound_routes', ['tenant_id' => $a->tenant_id, 'sip_number_id' => $number->id, 'destination_id' => $extension->id]);
        $this->assertDatabaseHas('outbound_routes', ['tenant_id' => $a->tenant_id, 'sip_extension_id' => $extension->id, 'gateway_id' => $gateway->id]);
        $password = session('phone_credentials.password');
        $this->get(self::HOST.'/setup/phone/'.$extension->id)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee($password);
        $this->get(self::HOST.'/setup/phone/'.$extension->id)->assertOk()->assertDontSee($password);
    }

    public function test_queue_membership_and_admin_extension_assignment_reject_foreign_extensions(): void
    {
        config(['voip.queues_enabled' => true]);
        $a = $this->owner();
        $b = $this->owner('+989120000002');
        $foreign = SipExtension::factory()->for($b->tenant)->create();
        $this->actingAs($a, 'customer')->post(self::HOST.'/teams', [
            'name' => 'Team', 'strategy' => 'ring-all', 'max_wait_seconds' => 90, 'member_ids' => [$foreign->id],
        ])->assertSessionHasErrors('member_ids');
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin, 'web')->put('https://admin.blucom.ir/admin/customers/'.$a->id, [
            'name' => 'Owner', 'enabled' => 1, 'sip_extension_id' => $foreign->id,
        ])->assertSessionHasErrors('sip_extension_id');
        $this->assertNull($a->fresh()->sip_extension_id);
        $a->update(['sip_extension_id' => $foreign->id]);
        $this->actingAs($a, 'customer')->get(self::HOST.'/availability')->assertOk()->assertDontSee($foreign->extension);
        $this->post(self::HOST.'/availability', ['status' => 'On Break'])->assertSessionHasErrors('status');
    }

    public function test_recording_and_audio_access_are_scoped_to_customer_tenant(): void
    {
        $a = $this->owner();
        $b = $this->owner('+989120000002');
        $number = SipNumber::factory()->for($b->tenant)->create();
        $recording = CallRecording::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $b->tenant_id,
            'sip_number_id' => $number->id, 'freeswitch_uuid' => (string) Str::uuid(), 'direction' => 'inbound',
            'status' => 'ready', 'expires_at' => now()->addDay(), 'policy' => []]);
        $this->actingAs($a, 'customer')->get(self::HOST.'/recordings')->assertOk()->assertDontSee($number->number);
        foreach (['/recordings/'.$recording->id.'/audio', '/recordings/'.$recording->id.'/download',
            '/recordings/numbers/'.$number->id.'/announcement', '/setup/answer/'.$number->id.'/announcement'] as $path) {
            $this->get(self::HOST.$path)->assertNotFound();
        }
        $this->delete(self::HOST.'/recordings/'.$recording->id)->assertNotFound();
        $this->put(self::HOST.'/recordings/storage/'.$b->tenant_id, ['quota_mb' => 128, 'overflow' => 'oldest'])->assertNotFound();
    }

    public function test_disabled_customer_or_business_cannot_use_existing_session(): void
    {
        $customer = $this->owner();
        $this->actingAs($customer, 'customer')->get(self::HOST.'/dashboard')->assertOk();
        $customer->tenant->update(['status' => 'disabled']);
        $this->get(self::HOST.'/auth/me')->assertForbidden();
        $this->get(self::HOST.'/setup/lines')->assertForbidden();
        $customer->tenant->update(['status' => 'active']);
        $customer->update(['disabled_at' => now()]);
        $this->get(self::HOST.'/dashboard')->assertForbidden();
        $this->post(self::HOST.'/notifications/read-all')->assertForbidden();
    }

    public function test_only_admin_can_disable_customer_business_and_internal_workspace_is_preserved(): void
    {
        $customer = $this->owner();
        $baseline = app(BlucomOwner::class)->get();
        $this->actingAs($customer, 'customer')->put(self::HOST.'/admin/customer-businesses/'.$customer->tenant_id,
            ['status' => 'disabled'])->assertNotFound();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin, 'web')->put('https://admin.blucom.ir/admin/customer-businesses/'.$customer->tenant_id,
            ['status' => 'disabled'])->assertRedirect();
        $this->assertSame('disabled', $customer->tenant()->first()->status);
        $this->put('https://admin.blucom.ir/admin/customer-businesses/'.$baseline->id, ['status' => 'disabled'])->assertNotFound();
        $this->assertSame('active', $baseline->fresh()->status);
        $extension = SipExtension::factory()->for($customer->tenant)->create();
        $this->assertStringNotContainsString('<user ', app(FreeSwitchDirectoryService::class)->buildAll($extension->extension));
    }

    public function test_unassigned_user_never_gets_provisioned_into_shared_workspace(): void
    {
        $user = User::factory()->create(['tenant_id' => null]);
        $before = app(BlucomOwner::class)->get()->sipNumbers()->count();
        try {
            app(TenantService::class)->forUser($user);
            $this->fail('Missing membership must fail closed.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertNull($user->fresh()->tenant_id);
        $this->assertSame($before, app(BlucomOwner::class)->get()->sipNumbers()->count());
        $customer = $this->owner();
        $customer->update(['tenant_id' => app(BlucomOwner::class)->get()->id]);
        $this->actingAs($customer, 'customer')->get(self::HOST.'/dashboard')->assertForbidden();
    }

    public function test_xml_rejects_foreign_destinations_and_forged_global_gateway_for_customer_business(): void
    {
        $a = $this->owner();
        $b = $this->owner('+989120000002');
        $extension = SipExtension::factory()->for($a->tenant)->create();
        $foreign = SipExtension::factory()->for($b->tenant)->create();
        $allowed = SipGateway::factory()->create(['tenant_id' => null]);
        $forged = SipGateway::factory()->create(['tenant_id' => null]);
        $number = SipNumber::factory()->for($a->tenant)->create(['provider_gateway_id' => $allowed->id]);
        InboundRoute::factory()->for($a->tenant)->create(['sip_number_id' => $number->id, 'destination_id' => $foreign->id]);
        OutboundRoute::factory()->for($a->tenant)->create(['sip_number_id' => $number->id, 'sip_extension_id' => $extension->id, 'gateway_id' => $forged->id]);
        $dialplans = app(FreeSwitchDialplanService::class);
        $this->assertStringNotContainsString('inbound_'.$number->id, $dialplans->build('public'));
        $xml = $dialplans->build('default', ['variable_sip_auth_username' => $extension->extension, 'variable_tenant_id' => $b->tenant_id]);
        $this->assertStringNotContainsString('local_'.$foreign->id, $xml);
        $this->assertStringNotContainsString('sofia/gateway/'.$forged->name, $xml);
        $this->assertStringNotContainsString('outbound_gateway', app(FreeSwitchDirectoryService::class)->buildAll($extension->extension));
    }

    public function test_customer_live_state_and_broadcast_channels_cannot_select_foreign_tenant(): void
    {
        config(['voip.live.enabled' => true, 'voip.live.cache_store' => 'array', 'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key', 'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app']);
        require base_path('routes/channels.php');
        $a = $this->owner();
        $b = $this->owner('+989120000002');
        $own = SipExtension::factory()->for($a->tenant)->create(['display_name' => 'Own phone']);
        $foreign = SipExtension::factory()->for($b->tenant)->create(['display_name' => 'Foreign phone']);
        Cache::store('array')->put('voip:live:'.$b->tenant_id, ['connected' => true, 'updated_at' => now()->timestamp,
            'extensions' => [$foreign->id => ['registered' => true, 'calls' => []]]]);
        $this->actingAs($a, 'customer')->getJson(self::HOST.'/live/state?organization='.$b->tenant_id)
            ->assertOk()->assertJsonPath('tenant.id', $a->tenant_id)->assertJsonCount(1, 'extensions')
            ->assertJsonPath('extensions.0.id', $own->id)->assertDontSee('Foreign phone');
        $this->postJson(self::HOST.'/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-live-overview.'.$b->tenant_id])->assertForbidden();
        $this->postJson(self::HOST.'/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-live-overview.'.$a->tenant_id])->assertOk()->assertJsonStructure(['auth']);
        $a->tenant->update(['status' => 'disabled']);
        $this->postJson(self::HOST.'/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => 'private-live-overview.'.$a->tenant_id])->assertForbidden();
    }

    public function test_notification_ids_do_not_cross_customer_or_user_model_boundaries(): void
    {
        $a = $this->owner();
        $b = $this->owner('+989120000002');
        $user = User::factory()->create(['user_type' => UserType::Admin]);
        foreach ([$a, $b, $user] as $account) {
            $account->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'test', 'data' => ['message' => 'Private notification']]);
        }
        $this->actingAs($a, 'customer')->post(self::HOST.'/notifications/'.$b->notifications()->first()->id.'/read')->assertNotFound();
        $this->post(self::HOST.'/notifications/'.$user->notifications()->first()->id.'/read')->assertNotFound();
        $this->post(self::HOST.'/notifications/read-all')->assertRedirect();
        $this->assertNotNull($a->notifications()->first()->read_at);
        $this->assertNull($b->notifications()->first()->read_at);
        $this->assertNull($user->notifications()->first()->read_at);
    }

    public function test_read_only_ownership_audit_does_not_move_baseline_or_legacy_records(): void
    {
        $baseline = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($baseline)->create();
        DB::table('workspace_consolidation_log')->insert(['table_name' => 'sip_numbers', 'record_id' => $number->id, 'previous_tenant_id' => 99]);
        $this->artisan('customer:ownership-audit', ['--json' => true])->assertSuccessful();
        $this->assertSame($baseline->id, $number->fresh()->tenant_id);
        $this->assertDatabaseCount('customers', 0);
        $this->assertDatabaseHas('workspace_consolidation_log', ['record_id' => $number->id, 'previous_tenant_id' => 99]);
    }

    public function test_compiled_routes_keep_customer_auth_on_customer_domain(): void
    {
        $routes = app('router')->getRoutes()->compile();
        $compiled = new CompiledRouteCollection($routes['compiled'], $routes['attributes']);
        $compiled->setRouter(app('router'))->setContainer(app());
        $customerRoute = $compiled->match(Request::create(self::HOST.'/auth/otp/request', 'POST'));
        $internalRoute = $compiled->match(Request::create('https://admin.blucom.ir/auth/otp/request', 'POST'));
        $this->assertSame('customer.otp.request', $customerRoute->getName());
        $this->assertSame(\App\Http\Controllers\Customer\AuthController::class.'@requestOtp', $customerRoute->getActionName());
        $this->assertSame(AuthController::class.'@requestOtp', $internalRoute->getActionName());
    }
}
