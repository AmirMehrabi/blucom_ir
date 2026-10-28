<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Services\CustomerAccountService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class CustomerExtensionsTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $mobile = '+989120000001'): Customer
    {
        return app(CustomerAccountService::class)->createOwner('Test customer', $mobile, 'Test tenant');
    }

    public function test_customer_creates_connects_updates_and_resets_extension_without_a_line_workspace(): void
    {
        $owner = $this->owner();
        SipNumber::factory()->create(['tenant_id' => $owner->tenant_id, 'status' => 'assigned', 'inventory_state' => null]);
        $this->actingAs($owner, 'customer')->get('https://my.blucom.ir/extensions')->assertOk()->assertSee('افزودن داخلی');
        $this->post('https://my.blucom.ir/extensions', ['extension' => '2345', 'display_name' => 'Sales', 'tenant_id' => 999])
            ->assertRedirect()->assertSessionHas('phone_credentials');
        $phone = SipExtension::query()->where('tenant_id', $owner->tenant_id)->sole();
        $this->assertSame('2345', $phone->extension);
        $this->assertFalse($phone->outboundRoute()->exists());
        $password = $phone->password_encrypted;
        $url = 'https://my.blucom.ir/extensions/'.$phone->id;
        $this->get($url)->assertOk()->assertSee('Sales')->assertSee($password)->assertHeader('Cache-Control', 'no-store, private');
        $this->get($url)->assertOk()->assertDontSee($password);
        $this->put($url, ['enabled' => 0, 'display_name' => 'Reception', 'max_registrations' => 2])->assertRedirect();
        $this->assertFalse($phone->fresh()->enabled);
        $this->assertSame('Reception', $phone->fresh()->display_name);
        $this->post($url.'/reset')->assertRedirect()->assertSessionHas('phone_credentials');
        $this->assertNotSame($password, $phone->fresh()->password_encrypted);
        $this->put($url, ['enabled' => 0, 'max_registrations' => 6])->assertSessionHasErrors('max_registrations');
    }

    public function test_permissions_and_all_extension_endpoints_enforce_tenant_ownership(): void
    {
        $owner = $this->owner();
        $other = $this->owner('+989120000002');
        $phone = SipExtension::factory()->create(['tenant_id' => $other->tenant_id, 'display_name' => 'Other private phone']);
        $url = 'https://my.blucom.ir/extensions/'.$phone->id;
        $this->actingAs($owner, 'customer')->get('https://my.blucom.ir/extensions')->assertOk()->assertDontSee('Other private phone');
        $this->get($url)->assertNotFound();
        $this->put($url, ['enabled' => 0])->assertNotFound();
        $this->post($url.'/reset')->assertNotFound();
        $number = SipNumber::factory()->create(['tenant_id' => $other->tenant_id, 'status' => 'assigned']);
        $this->get('https://my.blucom.ir/extensions?number_id='.$number->id)->assertNotFound();
        $this->post('https://my.blucom.ir/extensions', ['extension' => '2345', 'display_name' => 'Bad', 'number_id' => $number->id])->assertNotFound();
        $owner->permissions()->where('permission', Permissions::PHONES_MANAGE)->delete();
        $this->get('https://my.blucom.ir/extensions')->assertForbidden();
        $this->post('https://my.blucom.ir/extensions', ['extension' => '2345', 'display_name' => 'Bad'])->assertForbidden();
        $this->put($url, ['enabled' => 0])->assertForbidden();
        $this->post($url.'/reset')->assertForbidden();
    }

    public function test_capacity_blocks_creation_and_reactivation_and_duplicate_numbers_are_rejected(): void
    {
        $owner = $this->owner();
        $phone = SipExtension::factory()->create(['tenant_id' => $owner->tenant_id, 'enabled' => false]);
        $this->actingAs($owner, 'customer')->post('https://my.blucom.ir/extensions', ['extension' => '2345', 'display_name' => 'No plan'])->assertSessionHasErrors('line');
        $this->put('https://my.blucom.ir/extensions/'.$phone->id, ['enabled' => 1])->assertSessionHasErrors('line');
        $this->assertFalse($phone->fresh()->enabled);
        SipNumber::factory()->create(['tenant_id' => $owner->tenant_id, 'status' => 'assigned', 'inventory_state' => null]);
        $this->post('https://my.blucom.ir/extensions', ['extension' => $phone->extension, 'display_name' => 'Duplicate'])->assertSessionHasErrors('extension');
    }

    public function test_live_registration_and_line_return_journey_are_scoped_and_require_explicit_routing_save(): void
    {
        config(['voip.live.enabled' => true, 'voip.live.cache_store' => 'array']);
        $owner = $this->owner();
        $phone = SipExtension::factory()->create(['tenant_id' => $owner->tenant_id]);
        $line = SipNumber::factory()->create(['tenant_id' => $owner->tenant_id, 'status' => 'assigned', 'inventory_state' => null]);
        Cache::store('array')->put('voip:live:'.$owner->tenant_id, ['connected' => true, 'updated_at' => time(), 'extensions' => [$phone->id => ['registered' => true, 'devices' => 1, 'calls' => []]]]);
        $this->actingAs($owner, 'customer')->get('https://my.blucom.ir/extensions')->assertOk()->assertSee('تلفن متصل است');
        $this->get('https://my.blucom.ir/extensions/'.$phone->id.'?number_id='.$line->id)->assertOk()->assertSee('انتخاب این داخلی برای خط');
        $this->get('https://my.blucom.ir/lines/'.$line->id.'?extension_id='.$phone->id)->assertOk()->assertViewHas('selectedExtension', fn ($selected) => $selected->id === $phone->id);
        $this->assertFalse($line->inboundRoute()->exists());
        $this->assertFalse($phone->outboundRoute()->exists());
        $other = SipExtension::factory()->create();
        $this->get('https://my.blucom.ir/lines/'.$line->id.'?extension_id='.$other->id)->assertNotFound();
        $owner->permissions()->where('permission', Permissions::LIVE_VIEW)->delete();
        $this->get('https://my.blucom.ir/extensions')->assertSee('وضعیت اتصال نامشخص')->assertDontSee('تلفن متصل است');
        Cache::store('array')->put('voip:live:'.$owner->tenant_id, ['connected' => true, 'updated_at' => time() - 30, 'extensions' => []]);
        $this->get('https://my.blucom.ir/extensions')->assertSee('وضعیت اتصال نامشخص');
    }
}
