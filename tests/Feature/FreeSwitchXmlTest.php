<?php

namespace Tests\Feature;

use App\Models\InboundRoute;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\BlucomOwner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FreeSwitchXmlTest extends TestCase
{
    use RefreshDatabase;

    private string $token = 'test-xml-curl-token';

    protected function setUp(): void
    {
        parent::setUp();
        config(['voip.xml_curl.token' => $this->token]);
    }

    public function test_rejects_request_without_token(): void
    {
        $this->postJson('/internal/freeswitch/xml', ['section' => 'directory'])
            ->assertUnauthorized();
    }

    public function test_rejects_request_with_wrong_token(): void
    {
        $this->withHeader('X-FS-Token', 'wrong')
            ->postJson('/internal/freeswitch/xml', ['section' => 'directory'])
            ->assertUnauthorized();
    }

    public function test_accepts_freeswitch_basic_auth(): void
    {
        $response = $this->withBasicAuth('freeswitch', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'directory',
                'tag_name' => 'domain',
                'key_name' => 'name',
                'key_value' => config('voip.directory_domain'),
                'user' => '9999',
            ]);

        $response->assertOk();
        $this->assertStringContainsString('status="not found"', $response->getContent());
    }

    public function test_returns_directory_xml_for_existing_extension(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $extension = SipExtension::factory()->for($tenant)->create([
            'extension' => '1000',
            'password_encrypted' => 'secret-pass',
        ]);

        $response = $this->withHeader('X-FS-Token', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'directory',
                'user' => '1000',
            ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=UTF-8');
        $this->assertStringContainsString('<document type="freeswitch/xml">', $response->getContent());
        $this->assertStringContainsString('id="1000"', $response->getContent());
        $this->assertStringContainsString('<users>', $response->getContent());
        $this->assertStringContainsString('name="dial-string"', $response->getContent());
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $dialStrings = $xml->xpath('/document/section/domain/params/param[@name="dial-string"]');
        $this->assertCount(1, $dialStrings);
        $this->assertSame(config('voip.directory_dial_string'), (string) $dialStrings[0]['value']);
        $this->assertStringContainsString('value="secret-pass"', $response->getContent());
        $this->assertStringContainsString('name="user_context"', $response->getContent());
        $this->assertSame('default', $extension->enabled ? 'default' : 'disabled');
    }

    public function test_returns_not_found_document_for_unknown_extension(): void
    {
        $response = $this->withHeader('X-FS-Token', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'directory',
                'user' => '9999',
            ]);

        $response->assertOk();
        $this->assertStringNotContainsString('<user', $response->getContent());
        $this->assertStringContainsString('status="not found"', $response->getContent());
    }

    public function test_core_xml_curl_directory_lookup_returns_enabled_users(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        SipExtension::factory()->for($tenant)->create(['extension' => '1000']);
        SipExtension::factory()->for($tenant)->disabled()->create(['extension' => '1001']);

        $response = $this->withBasicAuth('freeswitch', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'directory',
                'tag_name' => 'domain',
                'key_name' => 'name',
                'key_value' => config('voip.directory_domain'),
            ]);

        $response->assertOk();
        $this->assertStringContainsString('<users>', $response->getContent());
        $this->assertStringContainsString('id="1000"', $response->getContent());
        $this->assertStringNotContainsString('id="1001"', $response->getContent());
    }

    public function test_directory_lookup_rejects_a_different_domain(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        SipExtension::factory()->for($tenant)->create(['extension' => '1000']);

        $content = $this->withBasicAuth('freeswitch', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'directory',
                'tag_name' => 'domain',
                'key_name' => 'name',
                'key_value' => 'other.example',
            ])->getContent();

        $this->assertStringContainsString('status="not found"', $content);
        $this->assertStringNotContainsString('<user ', $content);
    }

    public function test_does_not_return_disabled_extension(): void
    {
        SipExtension::factory()->disabled()->create([
            'extension' => '1001',
            'password_encrypted' => 'nope',
        ]);

        $response = $this->withHeader('X-FS-Token', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'directory',
                'user' => '1001',
            ]);

        $response->assertOk();
        $this->assertStringNotContainsString('<user', $response->getContent());
    }

    public function test_public_dialplan_transfers_inbound_did_to_extension(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create([
            'number' => '982191093464',
            'normalized_number' => '+982191093464',
        ]);
        $extension = SipExtension::factory()->for($tenant)->create(['extension' => '1000']);
        InboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'sip_number_id' => $number->id,
            'destination_id' => $extension->id,
            'enabled' => true,
        ]);

        $response = $this->withHeader('X-FS-Token', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan',
                'context' => 'public',
            ]);

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringContainsString('context name="public"', $content);
        $this->assertStringContainsString('982191093464', $content);
        $this->assertStringContainsString('application="transfer"', $content);
        $this->assertStringContainsString('data="1000 XML default"', $content);
    }

    public function test_core_xml_curl_context_field_selects_public_dialplan(): void
    {
        $content = $this->withBasicAuth('freeswitch', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan',
                'tag_name' => 'context',
                'key_name' => 'name',
                'key_value' => 'public',
            ])->getContent();

        $this->assertStringContainsString('<context name="public"', $content);
    }

    public function test_live_dialplan_lookup_uses_hunt_context_with_empty_lookup_fields(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create([
            'number' => '982191093464',
            'normalized_number' => '+982191093464',
        ]);
        $extension = SipExtension::factory()->for($tenant)->create(['extension' => '2000']);
        InboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'sip_number_id' => $number->id,
            'destination_id' => $extension->id,
        ]);

        $response = $this->withBasicAuth('freeswitch', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan',
                'tag_name' => '',
                'key_name' => '',
                'key_value' => '',
                'Hunt-Context' => 'public',
                'Hunt-Destination-Number' => '982191093464',
            ]);

        $response->assertOk();
        $this->assertStringContainsString('<context name="public"', $response->getContent());
        $this->assertStringContainsString('data="2000 XML default"', $response->getContent());
    }

    public function test_conflicting_dialplan_context_fields_fail_closed(): void
    {
        $content = $this->withBasicAuth('freeswitch', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan',
                'tag_name' => '',
                'key_name' => '',
                'key_value' => '',
                'Hunt-Context' => 'public',
                'context' => 'default',
            ])->getContent();

        $this->assertStringContainsString('status="not found"', $content);
    }

    public function test_dialplan_request_without_a_context_fails_closed(): void
    {
        $content = $this->withBasicAuth('freeswitch', $this->token)
            ->post('/internal/freeswitch/xml', ['section' => 'dialplan'])
            ->getContent();

        $this->assertStringContainsString('status="not found"', $content);
        $this->assertStringNotContainsString('<context ', $content);
    }

    public function test_public_dialplan_does_not_transfer_unknown_or_disabled_routes(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create([
            'number' => '982191093464',
            'normalized_number' => '+982191093464',
        ]);
        $extension = SipExtension::factory()->for($tenant)->create(['extension' => '1000']);
        InboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'sip_number_id' => $number->id,
            'destination_id' => $extension->id,
            'enabled' => false,
        ]);

        $content = $this->withHeader('X-FS-Token', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan',
                'context' => 'public',
            ])
            ->getContent();

        $this->assertStringNotContainsString('application="transfer"', $content);
    }

    public function test_default_dialplan_bridges_only_through_approved_gateway(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $gateway = SipGateway::factory()->create(['name' => 'provider-trunk', 'approved_for_outbound' => true]);
        $number = SipNumber::factory()->for($tenant)->create([
            'number' => '982191093464',
            'normalized_number' => '+982191093464',
            'outbound_enabled' => true,
        ]);
        $extension = SipExtension::factory()->for($tenant)->create(['extension' => '1000']);
        OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'sip_extension_id' => $extension->id,
            'sip_number_id' => $number->id,
            'gateway_id' => $gateway->id,
            'enabled' => true,
        ]);

        $content = $this->withHeader('X-FS-Token', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan',
                'tag_name' => '',
                'key_name' => '',
                'key_value' => '',
                'Hunt-Context' => 'default',
                'variable_sip_auth_username' => '1000',
                'variable_effective_caller_id_number' => '1000',
            ])
            ->getContent();

        $this->assertStringContainsString('sofia/gateway/provider-trunk/', $content);
        $this->assertStringContainsString('\\d{7,15}', $content);
        $this->assertStringContainsString('effective_caller_id_number=982191093464', $content);
        $this->assertStringNotContainsString('sofia/gateway//', $content);
        $this->assertStringNotContainsString('provider-secret', $content);
    }

    public function test_default_dialplan_rings_enabled_blucom_extensions(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        SipExtension::factory()->for($tenant)->create(['extension' => '2000']);
        SipExtension::factory()->for($tenant)->disabled()->create(['extension' => '2001']);
        SipExtension::factory()->for(Tenant::factory()->create())->create(['extension' => '3000']);

        $content = $this->withBasicAuth('freeswitch', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan',
                'tag_name' => 'context',
                'key_name' => 'name',
                'key_value' => 'default',
            ])->getContent();

        $this->assertStringContainsString('expression="^2000$"', $content);
        $this->assertStringContainsString('application="bridge" data="user/2000@'.config('voip.directory_domain').'"', $content);
        $this->assertStringNotContainsString('expression="^2001$"', $content);
        $this->assertStringNotContainsString('expression="^3000$"', $content);
        $this->assertStringNotContainsString('sofia/gateway/', $content);
    }

    public function test_default_dialplan_denies_outbound_when_no_route_exists(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        SipExtension::factory()->for($tenant)->create(['extension' => '1000']);

        $content = $this->withHeader('X-FS-Token', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan',
                'context' => 'default',
                'variable_sip_auth_username' => '1000',
            ])
            ->getContent();

        $this->assertStringNotContainsString('sofia/gateway/', $content);
    }

    public function test_default_dialplan_denies_outbound_for_unknown_caller(): void
    {
        $gateway = SipGateway::factory()->create(['name' => 'provider-trunk', 'approved_for_outbound' => true]);
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create();
        OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'sip_number_id' => $number->id,
            'gateway_id' => $gateway->id,
        ]);

        $content = $this->withHeader('X-FS-Token', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan',
                'context' => 'default',
                'variable_sip_auth_username' => '4040',
            ])
            ->getContent();

        $this->assertStringNotContainsString('sofia/gateway/', $content);
    }

    public function test_gateway_password_is_not_serialized_on_model(): void
    {
        $gateway = SipGateway::factory()->create([
            'password_encrypted' => 'super-secret',
        ]);

        $array = $gateway->toArray();

        $this->assertArrayNotHasKey('password_encrypted', $array);
        $this->assertSame('super-secret', $gateway->password_encrypted);
    }

    public function test_disabled_did_has_no_inbound_route(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create(['enabled' => false]);
        $extension = SipExtension::factory()->for($tenant)->create();
        InboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'sip_number_id' => $number->id,
            'destination_id' => $extension->id,
        ]);

        $xml = $this->withHeader('X-FS-Token', $this->token)
            ->post('/internal/freeswitch/xml', ['section' => 'dialplan', 'context' => 'public'])
            ->getContent();

        $this->assertStringNotContainsString('application="transfer"', $xml);
    }

    public function test_spoofed_caller_id_and_gateway_do_not_change_outbound_route(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        $extension = SipExtension::factory()->for($tenant)->create(['extension' => '1000']);
        $number = SipNumber::factory()->for($tenant)->create(['normalized_number' => '+982191093464']);
        $gateway = SipGateway::factory()->create(['name' => 'provider-trunk', 'approved_for_outbound' => true]);
        OutboundRoute::factory()->create([
            'tenant_id' => $tenant->id,
            'sip_extension_id' => $extension->id,
            'sip_number_id' => $number->id,
            'gateway_id' => $gateway->id,
        ]);

        $xml = $this->withHeader('X-FS-Token', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan',
                'context' => 'default',
                'variable_sip_auth_username' => '1000',
                'variable_effective_caller_id_number' => '999999999',
                'gateway' => 'evil',
            ])->getContent();

        $this->assertStringContainsString('sofia/gateway/provider-trunk/', $xml);
        $this->assertStringContainsString('effective_caller_id_number=982191093464', $xml);
        $this->assertStringNotContainsString('evil', $xml);
        $this->assertStringNotContainsString('999999999', $xml);
    }

    public function test_untrusted_caller_fields_cannot_resolve_an_extension(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        SipExtension::factory()->for($tenant)->create(['extension' => '1000']);
        $number = SipNumber::factory()->for($tenant)->create();
        $gateway = SipGateway::factory()->create(['name' => 'provider-trunk', 'approved_for_outbound' => true]);
        OutboundRoute::factory()->create(['tenant_id' => $tenant->id, 'sip_number_id' => $number->id, 'gateway_id' => $gateway->id]);

        $xml = $this->withHeader('X-FS-Token', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'dialplan', 'context' => 'default',
                'variable_user' => '1000', 'Caller-Caller-ID-Number' => '1000',
            ])->getContent();

        $this->assertStringNotContainsString('sofia/gateway/', $xml);
    }

    public function test_customer_extension_is_served_by_directory_xml(): void
    {
        $foreign = Tenant::factory()->create();
        SipExtension::factory()->for($foreign)->create(['extension' => '1234']);

        $xml = $this->withHeader('X-FS-Token', $this->token)
            ->post('/internal/freeswitch/xml', [
                'section' => 'directory',
                'user' => '1234',
            ])->getContent();

        $this->assertStringContainsString('<user id="1234">', $xml);
    }
}
