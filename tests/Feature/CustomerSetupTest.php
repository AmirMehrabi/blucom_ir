<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\OtpChallenge;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerSetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_submit_provider_number_and_answerer_without_activating_unverified_calls(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = User::factory()->create(['user_type' => UserType::Customer, 'tenant_id' => $tenant->id]);

        $this->actingAs($customer)->post('/setup/providers', [
            'display_name' => 'Main office', 'provider_name' => 'Carrier A',
            'connection_method' => 'credentials', 'host' => 'sip.example.test',
            'username' => 'account', 'password' => 'private-secret',
        ])->assertRedirect('/setup/number');
        $gateway = SipGateway::query()->firstOrFail();
        $this->assertSame($tenant->id, $gateway->tenant_id);
        $this->assertSame(SipGateway::STATUS_PENDING, $gateway->verification_status);
        $this->assertFalse($gateway->enabled);
        $this->assertSame('private-secret', $gateway->password_encrypted);
        $this->assertNotSame('private-secret', DB::table('sip_gateways')->value('password_encrypted'));
        $this->actingAs($customer)->get('/setup/provider')->assertDontSee('private-secret');

        $this->actingAs($customer)->post('/setup/numbers', [
            'number' => '02191093464', 'gateway_id' => $gateway->id,
        ])->assertRedirect();
        $number = SipNumber::query()->firstOrFail();
        $this->assertSame('+982191093464', $number->normalized_number);
        $this->assertSame(SipNumber::STATUS_PENDING, $number->status);
        $this->assertSame($tenant->id, $number->tenant_id);

        $this->actingAs($customer)->post('/setup/answer/'.$number->id, [
            'answerer' => 'new', 'display_name' => 'Sara',
        ])->assertRedirect();
        $extension = SipExtension::query()->firstOrFail();
        $this->assertSame($tenant->id, $extension->tenant_id);
        $this->assertDatabaseHas('inbound_routes', ['sip_number_id' => $number->id, 'destination_id' => $extension->id]);
        $this->assertDatabaseHas('outbound_routes', ['sip_number_id' => $number->id, 'gateway_id' => $gateway->id]);
        $password = session('phone_credentials.password');
        $this->assertIsString($password);
        $this->actingAs($customer)->get('/setup/phone/'.$extension->id)->assertOk()->assertSee($password);
        $this->actingAs($customer)->get('/setup/phone/'.$extension->id)->assertOk()->assertDontSee($password);

        config(['voip.xml_curl.token' => 'test-token', 'voip.gateway_xml_enabled' => true]);
        $inbound = $this->withHeader('X-FS-Token', 'test-token')->post('/internal/freeswitch/xml', [
            'section' => 'dialplan', 'Hunt-Context' => 'public',
        ])->getContent();
        $this->assertStringNotContainsString('inbound_'.$number->id, $inbound);
        $gateways = $this->withHeader('X-FS-Token', 'test-token')->post('/internal/freeswitch/xml', [
            'section' => 'directory', 'purpose' => 'gateways', 'profile' => 'external',
        ])->getContent();
        $this->assertStringNotContainsString('customer-'.$tenant->id, $gateways);
    }

    public function test_review_activates_only_approved_customer_relationships(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = User::factory()->create(['user_type' => UserType::Customer, 'tenant_id' => $tenant->id]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($customer)->post('/setup/providers', [
            'display_name' => 'Second office', 'provider_name' => 'Carrier B',
            'connection_method' => 'ip', 'host' => 'sip2.example.test',
        ])->assertRedirect();
        $gateway = SipGateway::query()->firstOrFail();
        $this->assertFalse($gateway->register);
        $this->assertNull($gateway->password_encrypted);

        $this->actingAs($customer)->post('/setup/numbers', ['number' => '+982191093465', 'gateway_id' => $gateway->id])->assertRedirect();
        $number = SipNumber::query()->firstOrFail();
        $this->actingAs($customer)->post('/setup/answer/'.$number->id, ['answerer' => 'new', 'display_name' => 'Ali'])->assertRedirect();
        $extension = SipExtension::query()->firstOrFail();

        $this->actingAs($admin)->post('/admin/customer-connections/numbers/'.$number->id.'/approve')->assertSessionHasErrors('number');
        $this->actingAs($admin)->post('/admin/customer-connections/gateways/'.$gateway->id.'/approve')->assertRedirect();
        $this->actingAs($admin)->post('/admin/customer-connections/numbers/'.$number->id.'/approve')->assertRedirect();
        $this->assertSame(SipNumber::STATUS_ASSIGNED, $number->fresh()->status);

        config(['voip.xml_curl.token' => 'test-token', 'voip.gateway_xml_enabled' => false]);
        $gated = $this->withHeader('X-FS-Token', 'test-token')->post('/internal/freeswitch/xml', [
            'section' => 'dialplan', 'Hunt-Context' => 'public',
        ])->getContent();
        $this->assertStringNotContainsString('inbound_'.$number->id, $gated);

        config(['voip.xml_curl.token' => 'test-token', 'voip.gateway_xml_enabled' => true]);
        $inbound = $this->withHeader('X-FS-Token', 'test-token')->post('/internal/freeswitch/xml', [
            'section' => 'dialplan', 'Hunt-Context' => 'public',
        ])->getContent();
        $this->assertStringContainsString('inbound_'.$number->id, $inbound);
        $this->assertStringContainsString('user/'.$extension->extension.'@', $inbound);
        $directory = $this->withHeader('X-FS-Token', 'test-token')->post('/internal/freeswitch/xml', [
            'section' => 'directory', 'user' => $extension->extension,
        ])->getContent();
        $this->assertStringContainsString('<user id="'.$extension->extension.'">', $directory);
    }

    public function test_customer_cannot_use_another_tenants_provider_number_or_phone(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $customerA = User::factory()->create(['user_type' => UserType::Customer, 'tenant_id' => $tenantA->id]);
        $gatewayB = SipGateway::factory()->create(['tenant_id' => $tenantB->id, 'verification_status' => 'approved']);
        $numberB = SipNumber::factory()->for($tenantB)->create(['provider_gateway_id' => $gatewayB->id]);
        $extensionB = SipExtension::factory()->for($tenantB)->create();

        $this->actingAs($customerA)->post('/setup/numbers', [
            'number' => '02191093466', 'gateway_id' => $gatewayB->id,
        ])->assertSessionHasErrors('gateway_id');
        $this->actingAs($customerA)->get('/setup/answer/'.$numberB->id)->assertNotFound();
        $this->actingAs($customerA)->post('/setup/answer/'.$numberB->id, [
            'answerer' => 'existing', 'extension_id' => $extensionB->id,
        ])->assertNotFound();
        $this->actingAs($customerA)->get('/setup/phone/'.$extensionB->id)->assertNotFound();
        $this->actingAs($customerA)->post('/setup/phone/'.$extensionB->id.'/reset')->assertNotFound();
        $this->actingAs($customerA)->post('/admin/customer-connections/gateways/'.$gatewayB->id.'/approve')->assertForbidden();
    }

    public function test_forged_cross_tenant_outbound_route_does_not_bridge(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();
        $extension = SipExtension::factory()->for($tenantA)->create(['extension' => '2345']);
        $gateway = SipGateway::factory()->create([
            'tenant_id' => $tenantB->id, 'verification_status' => 'approved', 'approved_for_outbound' => true,
        ]);
        $number = SipNumber::factory()->for($tenantA)->create(['provider_gateway_id' => $gateway->id]);
        OutboundRoute::factory()->for($tenantA)->create([
            'sip_extension_id' => $extension->id,
            'sip_number_id' => $number->id,
            'gateway_id' => $gateway->id,
        ]);

        config(['voip.xml_curl.token' => 'test-token']);
        $xml = $this->withHeader('X-FS-Token', 'test-token')->post('/internal/freeswitch/xml', [
            'section' => 'dialplan', 'Hunt-Context' => 'default',
            'variable_sip_auth_username' => $extension->extension,
        ])->getContent();
        $this->assertStringNotContainsString('sofia/gateway/'.$gateway->name, $xml);
    }

    public function test_verified_otp_creates_customer_account_on_hub(): void
    {
        $mobile = '+989123456789';
        $code = '123456';
        $challenge = OtpChallenge::query()->create([
            'id' => (string) Str::uuid(), 'mobile' => $mobile,
            'code_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(5),
        ]);

        $this->postJson('http://hub.blucom.local/auth/otp/verify', [
            'mobile' => $mobile, 'code' => $code, 'challenge_id' => $challenge->id,
        ])->assertOk()->assertJsonPath('redirect', '/setup/provider');
        $this->assertDatabaseHas('users', ['mobile' => $mobile, 'user_type' => 'customer']);
    }

    public function test_customer_can_correct_rejected_provider_and_number_without_exposing_password(): void
    {
        $tenant = Tenant::factory()->create();
        $customer = User::factory()->create(['user_type' => UserType::Customer, 'tenant_id' => $tenant->id]);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);

        $this->actingAs($customer)->post('/setup/providers', [
            'display_name' => 'Main', 'provider_name' => 'Carrier', 'connection_method' => 'credentials',
            'host' => 'old.example.test', 'username' => 'account', 'password' => 'old-secret',
        ])->assertRedirect();
        $gateway = SipGateway::query()->firstOrFail();
        $this->actingAs($customer)->post('/setup/numbers', ['number' => '02191093460', 'gateway_id' => $gateway->id])->assertRedirect();
        $number = SipNumber::query()->firstOrFail();

        $this->actingAs($admin)->post('/admin/customer-connections/gateways/'.$gateway->id.'/reject')->assertRedirect();
        $this->actingAs($admin)->post('/admin/customer-connections/numbers/'.$number->id.'/reject')->assertRedirect();
        $this->actingAs($customer)->get('/setup/provider?edit='.$gateway->id)->assertOk()->assertDontSee('old-secret');
        $this->actingAs($customer)->put('/setup/providers/'.$gateway->id, [
            'display_name' => 'Corrected', 'provider_name' => 'Carrier', 'connection_method' => 'credentials',
            'host' => 'new.example.test', 'username' => 'account', 'password' => 'new-secret',
        ])->assertRedirect();
        $this->assertSame(SipGateway::STATUS_PENDING, $gateway->fresh()->verification_status);
        $this->assertSame('new.example.test', $gateway->fresh()->host);

        $this->actingAs($customer)->put('/setup/numbers/'.$number->id, [
            'number' => '۰۲۱۹۱۰۹۳۴۶۱', 'gateway_id' => $gateway->id,
        ])->assertRedirect();
        $this->assertSame(SipNumber::STATUS_PENDING, $number->fresh()->status);
        $this->assertSame('+982191093461', $number->fresh()->normalized_number);

        $this->actingAs($admin)->post('/admin/customer-connections/gateways/'.$gateway->id.'/approve')->assertRedirect();
        $this->actingAs($customer)->put('/setup/providers/'.$gateway->id, [
            'display_name' => 'Bad edit', 'provider_name' => 'Carrier', 'connection_method' => 'credentials',
            'host' => 'bad.example.test', 'username' => 'account', 'password' => 'new-secret',
        ])->assertNotFound();
    }
}
