<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\SipGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SipGatewayProvisioningTest extends TestCase
{
    use RefreshDatabase;

    public function test_gateway_lookup_is_gated_and_returns_only_valid_enabled_external_gateways(): void
    {
        config(['voip.xml_curl.token' => 'test-token']);
        $active = SipGateway::factory()->create([
            'name' => 'carrier-a', 'host' => 'sip.example.test', 'register' => true,
            'username' => 'carrier-user', 'password_encrypted' => 'private-password',
            'realm' => 'example.test', 'approved_for_outbound' => true,
        ]);
        SipGateway::factory()->disabled()->create(['name' => 'disabled-carrier']);
        SipGateway::factory()->create(['name' => 'incomplete', 'register' => true]);

        $request = [
            'section' => 'directory', 'purpose' => 'gateways', 'profile' => 'external',
            'tag_name' => '', 'key_name' => '', 'key_value' => '',
        ];
        $this->withBasicAuth('freeswitch', 'test-token')
            ->post('/internal/freeswitch/xml', $request)
            ->assertOk()
            ->assertSee('status="not found"', false);

        config(['voip.gateway_xml_enabled' => true]);
        $response = $this->withBasicAuth('freeswitch', 'test-token')
            ->post('/internal/freeswitch/xml', $request);
        $response->assertOk();
        $xml = simplexml_load_string($response->getContent());
        $this->assertNotFalse($xml);
        $gateways = $xml->xpath('/document/section[@name="directory"]/domain/groups/group/users/user/gateways/gateway');
        $this->assertCount(1, $gateways);
        $this->assertSame($active->name, (string) $gateways[0]['name']);
        $this->assertStringContainsString('value="private-password"', $response->getContent());
        $this->assertStringContainsString('name="context" value="public"', $response->getContent());

        $this->withBasicAuth('freeswitch', 'test-token')
            ->post('/internal/freeswitch/xml', array_replace($request, ['profile' => 'internal']))
            ->assertSee('status="not found"', false);
    }

    public function test_admin_gateway_creation_requires_credentials_for_registration_and_does_not_render_password(): void
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin)->post('/sip-gateways', [
            'name' => 'carrier-a', 'host' => 'sip.example.test', 'port' => 5060,
            'transport' => 'udp', 'register' => 1,
        ])->assertSessionHasErrors('register');

        $this->actingAs($admin)->post('/sip-gateways', [
            'name' => 'carrier-a', 'host' => 'sip.example.test', 'port' => 5060,
            'transport' => 'udp', 'register' => 1, 'username' => 'carrier-user',
            'password' => 'private-password', 'approved_for_outbound' => 1,
        ])->assertRedirect();

        $gateway = SipGateway::query()->where('name', 'carrier-a')->firstOrFail();
        $this->assertSame('private-password', $gateway->password_encrypted);
        $this->assertTrue($gateway->approved_for_outbound);
        $this->assertStringNotContainsString('private-password', $this->actingAs($admin)->get('/sip-gateways')->getContent());

        $this->actingAs($admin)->put('/sip-gateways/'.$gateway->id, [
            'username' => '', 'register' => 1,
        ])->assertSessionHasErrors('register');
        $this->assertSame('carrier-user', $gateway->fresh()->username);
    }
}
