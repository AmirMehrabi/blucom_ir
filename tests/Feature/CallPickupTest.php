<?php

namespace Tests\Feature;

use App\Models\PickupGroup;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\CallPickupService;
use App\Services\CustomerAccountService;
use App\Services\FreeSwitchDialplanService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CallPickupTest extends TestCase
{
    use RefreshDatabase;

    public function test_pickup_targets_require_every_switch_and_same_tenant_group(): void
    {
        config(['voip.pickup_enabled' => true]);
        $tenant = Tenant::factory()->create(['pickup_enabled' => true]);
        $group = PickupGroup::create(['tenant_id' => $tenant->id, 'name' => 'Sales']);
        $caller = SipExtension::factory()->create(['tenant_id' => $tenant->id, 'pickup_group_id' => $group->id, 'pickup_allowed' => true]);
        $target = SipExtension::factory()->create(['tenant_id' => $tenant->id, 'extension' => '2345', 'pickup_group_id' => $group->id]);
        SipExtension::factory()->create(['tenant_id' => $tenant->id]);
        SipExtension::factory()->create(['pickup_group_id' => $group->id]); // malformed cross-tenant membership
        $service = app(CallPickupService::class);
        $this->assertSame([$target->id], $service->targets($caller, '*8'));
        $this->assertSame([$target->id], $service->targets($caller, '**2345'));
        foreach (['**9999', '**2345;bridge', '*9'] as $code) {
            $this->assertSame([], $service->targets($caller, $code));
        }
        foreach (['pickup_enabled', 'pickup_directed', 'pickup_group'] as $key) {
            $tenant->update([$key => false]);
            $caller->unsetRelation('tenant');
            $this->assertSame([], $service->targets($caller, $key === 'pickup_directed' ? '**2345' : '*8'));
            $tenant->update([$key => true]);
        }
        $caller->unsetRelation('tenant');
        $this->assertSame([$target->id], $service->targets($caller, '*8'));
        $caller->update(['pickup_allowed' => false]);
        $this->assertSame([], $service->targets($caller, '*8'));
        $caller->update(['pickup_allowed' => true]);
        $target->update(['enabled' => false]);
        $this->assertSame([], $service->targets($caller, '*8'));
        $target->update(['enabled' => true]);
        config(['voip.pickup_enabled' => false]);
        $this->assertSame([], $service->targets($caller, '*8'));
        config(['voip.pickup_enabled' => true]);
        $tenant->update(['status' => 'suspended']);
        $caller->unsetRelation('tenant');
        $this->assertSame([], $service->targets($caller, '*8'));
    }

    public function test_dialplan_denies_unauthenticated_disabled_and_public_pickup_without_outbound_fallback(): void
    {
        config(['voip.pickup_enabled' => true]);
        $tenant = Tenant::factory()->create(['pickup_enabled' => true]);
        $group = PickupGroup::create(['tenant_id' => $tenant->id, 'name' => 'Sales']);
        $caller = SipExtension::factory()->create(['tenant_id' => $tenant->id, 'pickup_group_id' => $group->id, 'pickup_allowed' => true]);
        $target = SipExtension::factory()->create(['tenant_id' => $tenant->id, 'pickup_group_id' => $group->id]);
        $service = app(FreeSwitchDialplanService::class);
        $request = ['Hunt-Destination-Number' => '*8', 'variable_sip_auth_username' => $caller->sipIdentity()];
        $xml = $service->build('default', $request);
        $this->assertStringContainsString('blucom_pickup.lua '.$caller->sipIdentity().' '.$target->id, $xml);
        $this->assertStringNotContainsString('sofia/gateway/', $xml);
        config(['voip.xml_curl.token' => 'pickup-test-token']);
        $this->withHeader('X-FS-Token', 'pickup-test-token')->post('/internal/freeswitch/xml', $request + [
            'section' => 'dialplan', 'Hunt-Context' => 'default',
        ])->assertOk()->assertSee('blucom_pickup.lua '.$caller->sipIdentity().' '.$target->id, false);
        $this->assertStringNotContainsString('blucom_pickup.lua', $service->build('public', $request));
        $this->assertStringContainsString('CALL_REJECTED', $service->build('default', ['destination_number' => '*8', 'variable_tenant_id' => $tenant->id]));
        $caller->update(['pickup_allowed' => false]);
        $this->assertStringContainsString('CALL_REJECTED', $service->build('default', $request));
        $xml = $service->build('default', ['destination_number' => $target->extension, 'variable_sip_auth_username' => $caller->sipIdentity()]);
        $this->assertStringContainsString('blucom_pickup_target='.$target->id, $xml);
        $this->assertStringContainsString('application="unset" data="blucom_pickup_target"', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
    }

    public function test_customer_settings_are_scoped_permission_checked_and_apply_on_next_lookup(): void
    {
        $owner = app(CustomerAccountService::class)->createOwner('Owner', '+989120000001', 'Tenant');
        $phone = SipExtension::factory()->create(['tenant_id' => $owner->tenant_id]);
        $other = SipExtension::factory()->create();
        $foreign = PickupGroup::create(['tenant_id' => $other->tenant_id, 'name' => 'Private group']);
        $url = 'https://my.blucom.ir/extensions/call-pickup';
        $this->actingAs($owner, 'customer')->get($url)->assertOk()->assertDontSee('Private group');
        $data = ['pickup_enabled' => 1, 'pickup_directed' => 1, 'pickup_group' => 1, 'new_group' => 'Sales'];
        $this->put($url, $data)->assertRedirect($url);
        $group = PickupGroup::query()->where('tenant_id', $owner->tenant_id)->sole();
        unset($data['new_group']);
        $data['extensions'] = [['id' => $phone->id, 'pickup_group_id' => $group->id, 'pickup_allowed' => 1]];
        $this->put($url, $data)->assertRedirect();
        $this->assertTrue($phone->fresh()->pickup_allowed);
        $data['extensions'][0]['pickup_group_id'] = $foreign->id;
        $data['pickup_enabled'] = 0;
        $this->put($url, $data)->assertSessionHasErrors('extensions.0.pickup_group_id');
        $this->assertTrue($owner->tenant->fresh()->pickup_enabled);
        $data['extensions'][0] = ['id' => $other->id, 'pickup_group_id' => null, 'pickup_allowed' => 1];
        $this->put($url, $data)->assertSessionHasErrors('extensions.0.id');
        $this->put($url, ['pickup_enabled' => 0, 'pickup_directed' => 0, 'pickup_group' => 0, 'delete_group' => $group->id])->assertRedirect();
        $this->assertNull($phone->fresh()->pickup_group_id);
        $owner->permissions()->where('permission', Permissions::PHONES_MANAGE)->delete();
        $this->get($url)->assertForbidden();
        $this->put($url, $data)->assertForbidden();
    }

    public function test_subscription_revocation_blocks_pickup(): void
    {
        config(['voip.pickup_enabled' => true]);
        $owner = app(CustomerAccountService::class)->createOwner('Owner', '+989120000001', 'Tenant');
        $owner->tenant->update(['pickup_enabled' => true]);
        $group = PickupGroup::create(['tenant_id' => $owner->tenant_id, 'name' => 'Sales']);
        $caller = SipExtension::factory()->create(['tenant_id' => $owner->tenant_id, 'pickup_allowed' => true, 'pickup_group_id' => $group->id]);
        $target = SipExtension::factory()->create(['tenant_id' => $owner->tenant_id, 'pickup_group_id' => $group->id]);
        $this->assertSame([], app(CallPickupService::class)->targets($caller, '*8'));
        SipNumber::factory()->create(['tenant_id' => $owner->tenant_id, 'inventory_state' => null, 'status' => 'assigned']);
        $this->assertSame([$target->id], app(CallPickupService::class)->targets($caller, '*8'));
    }
}
