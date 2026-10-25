<?php

namespace Tests\Feature;

use App\Models\LineConnectionRequest;
use App\Models\SipNumber;
use App\Services\CustomerAccountService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PortalJourneyTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_has_two_paths_without_provider_infrastructure_access(): void
    {
        config(['commerce.catalog_enabled' => true, 'commerce.separated_billing_enabled' => true]);
        $owner = app(CustomerAccountService::class)->createOwner('Owner', '+989120001201', 'Business');
        $this->actingAs($owner, 'customer')->get('https://my.blucom.ir/start')->assertOk()->assertSee('انتخاب شماره جدید')->assertSee('شروع اتصال شماره فعلی');
        $this->assertFalse($owner->hasPermission('providers.manage'));
        $this->post('https://my.blucom.ir/start/existing', ['number' => '02155501234', 'provider_name' => 'Example', 'tenant_id' => 999])->assertRedirect();
        $this->assertSame($owner->tenant_id, LineConnectionRequest::query()->sole()->tenant_id);
        $this->assertDatabaseCount('sip_gateways', 0);
        $this->assertDatabaseCount('sip_numbers', 0);
    }

    public function test_connection_requests_and_next_steps_are_tenant_scoped(): void
    {
        $accounts = app(CustomerAccountService::class);
        $owner = $accounts->createOwner('First', '+989120001202', 'First business');
        $other = $accounts->createOwner('Second', '+989120001203', 'Other business');
        LineConnectionRequest::query()->create(['tenant_id' => $other->tenant_id, 'customer_id' => $other->id, 'normalized_number' => '+982155501299', 'provider_name' => 'Other private provider']);
        $number = SipNumber::factory()->create(['tenant_id' => $owner->tenant_id, 'status' => 'assigned']);
        $this->actingAs($owner, 'customer')->get('https://my.blucom.ir/start/existing')->assertOk()->assertDontSee('Other private provider');
        $this->get('https://my.blucom.ir/dashboard')->assertOk()->assertSee('ادامه تنظیم خط')->assertSee('/lines/'.$number->id);
        $staff = $accounts->createStaff($owner->tenant, 'Staff', '+989120001204', Permissions::CUSTOMER_STAFF_DEFAULTS);
        $this->actingAs($staff, 'customer')->post('https://my.blucom.ir/start/existing', ['number' => '+982155501233', 'provider_name' => 'Forbidden'])->assertForbidden();
    }
}
