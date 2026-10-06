<?php

namespace Tests\Feature;

use App\Enums\CustomerRole;
use App\Enums\UserType;
use App\Models\Customer;
use App\Models\NumberOffer;
use App\Models\PlanVersion;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Commerce\NumberInventoryService;
use App\Services\Commerce\NumberOfferService;
use App\Services\Commerce\NumberReadinessService;
use App\Services\Commerce\PlanService;
use App\Services\FreeSwitchDialplanService;
use App\Services\SipNumberService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class NumberCommerceTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['user_type' => UserType::Admin]);
    }

    private function stock(User $admin): SipNumber
    {
        $gateway = SipGateway::factory()->create(['tenant_id' => null, 'verification_status' => SipGateway::STATUS_APPROVED, 'approved_for_outbound' => true]);

        return app(NumberInventoryService::class)->create($admin, [
            'number' => '+982155501234', 'label' => 'Pilot stock', 'provider_gateway_id' => $gateway->id,
            'enabled' => true, 'inbound_enabled' => true, 'outbound_enabled' => true, 'destination_prefixes' => ['+9821', '+989'],
        ]);
    }

    private function version(User $admin): PlanVersion
    {
        $plans = app(PlanService::class);
        $plan = $plans->create($admin, 'Monthly inclusive');
        $version = $plans->version($admin, $plan->id, ['extensions' => 5, 'queues' => 2, 'ivr_menus' => 2]);
        $plans->publish($admin, $version->id);

        return $version->fresh();
    }

    private function review(User $admin, SipNumber $number): SipNumber
    {
        app(NumberInventoryService::class)->review($admin, $number->id, $number->inventory_revision, 'Synthetic technical review evidence');

        return $number->fresh();
    }

    public function test_admin_prepares_reviews_and_publishes_unowned_stock_in_toman(): void
    {
        $admin = $this->admin();
        $gateway = SipGateway::factory()->create(['tenant_id' => null, 'verification_status' => 'approved', 'approved_for_outbound' => true]);
        $this->actingAs($admin)->post('/admin/inventory', [
            'number' => '02155501234', 'provider_gateway_id' => $gateway->id,
            'enabled' => 1, 'inbound_enabled' => 1, 'outbound_enabled' => 1,
            'destination_prefixes_text' => '+9821 +989',
        ])->assertRedirect();
        $number = SipNumber::query()->firstOrFail();
        $this->assertNull($number->tenant_id);
        $this->assertSame('draft', $number->inventory_state);
        $this->assertSame('+982155501234', $number->normalized_number);
        $number = $this->review($admin, $number);
        $version = $this->version($admin);
        $this->actingAs($admin)->post('/admin/inventory/'.$number->id.'/publish', [
            'revision' => $number->inventory_revision, 'plan_version_id' => $version->id, 'monthly_amount' => 250000,
        ])->assertRedirect()->assertSessionHasNoErrors();
        $offer = NumberOffer::query()->firstOrFail();
        $this->assertSame('IRT', $offer->currency);
        $this->assertSame(250000, $offer->monthly_amount);
        $this->assertSame($offer->id, $number->fresh()->current_offer_id);
        $this->assertNull($number->fresh()->tenant_id);
        $this->assertDatabaseCount('inbound_routes', 0);
        $this->assertDatabaseCount('outbound_routes', 0);
        $this->actingAs($admin)->get('/admin/sip-numbers?scope=stock&publication=published')->assertOk()->assertSee('250,000 تومان');
        $this->actingAs($admin)->get('/admin/inventory/'.$number->id)->assertOk()->assertSee('250,000')->assertHeader('Cache-Control', 'no-store, private');
        $this->actingAs($admin)->get('/admin/plans')->assertOk()->assertSee('Monthly inclusive');
        $this->assertDatabaseHas('commerce_audit_events', ['actor_user_id' => $admin->id, 'event' => 'offer.published']);
        $this->assertFalse(config('commerce.checkout_enabled'));
    }

    public function test_readiness_and_stale_revision_deny_publication(): void
    {
        $admin = $this->admin();
        $number = $this->stock($admin);
        $version = $this->version($admin);
        $payload = ['revision' => $number->inventory_revision, 'plan_version_id' => $version->id, 'monthly_amount' => 250000];
        $this->actingAs($admin)->post('/admin/inventory/'.$number->id.'/publish', $payload)->assertSessionHasErrors('publication');
        $number = $this->review($admin, $number);
        $this->actingAs($admin)->post('/admin/inventory/'.$number->id.'/publish', $payload)->assertSessionHasErrors('inventory');
        $number->providerGateway->update(['enabled' => false]);
        $payload['revision'] = $number->inventory_revision;
        $this->actingAs($admin)->post('/admin/inventory/'.$number->id.'/publish', $payload)->assertSessionHasErrors('publication');
        $this->assertDatabaseCount('number_offers', 0);
    }

    public function test_withdrawal_preserves_old_prices_and_allows_new_offer(): void
    {
        $admin = $this->admin();
        $number = $this->review($admin, $this->stock($admin));
        $version = $this->version($admin);
        $offers = app(NumberOfferService::class);
        $first = $offers->publish($admin, $number->id, $number->inventory_revision, $version->id, 250000);
        $number->refresh();
        $this->actingAs($admin)->patch('/sip-gateways/'.$number->provider_gateway_id.'/status', ['enabled' => 0])->assertSessionHasErrors('gateway');
        $this->assertTrue($number->providerGateway->enabled);
        $this->actingAs($admin)->post('/admin/inventory/'.$number->id.'/publish', ['revision' => $number->inventory_revision, 'plan_version_id' => $version->id, 'monthly_amount' => 300000])->assertSessionHasErrors('inventory');
        $this->actingAs($admin)->post('/admin/plans/'.$version->plan_id.'/archive', ['reason' => 'Retire plan'])->assertSessionHasErrors('plan');
        $offers->withdraw($admin, $number->id, $number->inventory_revision, 'Price change');
        $number->refresh();
        $second = $offers->publish($admin, $number->id, $number->inventory_revision, $version->id, 300000);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame(250000, $first->fresh()->monthly_amount);
        $this->assertNotNull($first->fresh()->withdrawn_at);
        $this->assertSame(300000, $second->monthly_amount);
        $this->expectException(ValidationException::class);
        $first->update(['monthly_amount' => 1]);
    }

    public function test_draft_limits_edit_published_versions_and_archive_rules(): void
    {
        $admin = $this->admin();
        $plan = app(PlanService::class)->create($admin, 'Draft plan');
        $version = app(PlanService::class)->version($admin, $plan->id, ['extensions' => 3, 'queues' => 1, 'ivr_menus' => 1]);
        $this->actingAs($admin)->put('/admin/plan-versions/'.$version->id, ['extensions' => 7, 'queues' => 2, 'ivr_menus' => 2])->assertSessionHasNoErrors();
        $this->assertSame(7, $version->fresh()->limits['extensions']);
        $this->actingAs($admin)->post('/admin/plan-versions/'.$version->id.'/publish')->assertSessionHasNoErrors();
        $this->actingAs($admin)->put('/admin/plan-versions/'.$version->id, ['extensions' => 9, 'queues' => 2, 'ivr_menus' => 2])->assertSessionHasErrors('plan');
        $this->actingAs($admin)->post('/admin/plans/'.$plan->id.'/archive', ['reason' => 'Retire'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/plans/'.$plan->id.'/versions', ['extensions' => 1, 'queues' => 1, 'ivr_menus' => 1])->assertSessionHasErrors('plan');
        $this->assertDatabaseCount('plan_versions', 1);
        $this->expectException(ValidationException::class);
        $version->fresh()->update(['limits' => ['extensions' => 50]]);
    }

    public function test_customer_and_operator_cannot_access_commerce_admin_endpoints(): void
    {
        $admin = $this->admin();
        $number = $this->stock($admin);
        $operator = User::factory()->create(['user_type' => UserType::Operator]);
        foreach (['/admin/plans', '/admin/inventory/'.$number->id] as $url) {
            $this->actingAs($operator)->get($url)->assertForbidden();
        }
        $this->actingAs($operator)->post('/admin/inventory', [])->assertForbidden();
        $tenant = Tenant::factory()->create(['owner_user_id' => null, 'system_key' => null]);
        $customer = Customer::query()->create(['tenant_id' => $tenant->id, 'name' => 'Owner', 'mobile' => '09125550123', 'role' => CustomerRole::Owner]);
        $tenant->update(['owner_customer_id' => $customer->id]);
        $this->actingAs($customer, 'customer')->get('https://my.blucom.ir/admin/plans')->assertForbidden();
        $this->actingAs($customer, 'customer')->post('https://my.blucom.ir/admin/inventory/'.$number->id.'/publish', [])->assertForbidden();
    }

    public function test_legacy_baseline_is_untouched_and_free_claim_cannot_assign_commerce_stock(): void
    {
        $admin = $this->admin();
        $legacy = SipNumber::factory()->create(['enabled' => true]);
        $attributes = $legacy->fresh()->getAttributes();
        $number = $this->stock($admin);
        $this->assertSame($attributes, $legacy->fresh()->getAttributes());
        $this->assertNull($legacy->inventory_state);
        $this->actingAs($admin)->post('/admin/inventory/'.$legacy->id.'/review', ['revision' => 1, 'evidence' => 'Never sell assigned legacy DID'])->assertSessionHasErrors('inventory');
        $this->expectException(ValidationException::class);
        app(SipNumberService::class)->assignToTenant($number, Tenant::factory()->create());
    }

    public function test_disabled_stock_duplicate_numbers_invalid_amount_and_prefixes_fail_safely(): void
    {
        $admin = $this->admin();
        $number = $this->stock($admin);
        $version = $this->version($admin);
        $this->actingAs($admin)->post('/admin/inventory/'.$number->id.'/transition', ['revision' => $number->inventory_revision, 'action' => 'disable', 'reason' => 'Not ready'])->assertSessionHasNoErrors();
        $number->refresh();
        $this->actingAs($admin)->post('/admin/inventory/'.$number->id.'/publish', ['revision' => $number->inventory_revision, 'plan_version_id' => $version->id, 'monthly_amount' => 250000])->assertSessionHasErrors('publication');
        $this->actingAs($admin)->post('/admin/inventory/'.$number->id.'/publish', ['revision' => $number->inventory_revision, 'plan_version_id' => $version->id, 'monthly_amount' => 2.5])->assertSessionHasErrors('monthly_amount');
        $this->actingAs($admin)->post('/admin/inventory', ['number' => '00982155501234', 'enabled' => 1, 'inbound_enabled' => 1, 'outbound_enabled' => 1])->assertSessionHasErrors('number');
        $this->actingAs($admin)->put('/admin/inventory/'.$number->id, ['revision' => $number->inventory_revision, 'enabled' => 1, 'inbound_enabled' => 1, 'outbound_enabled' => 1, 'destination_prefixes_text' => 'sofia/gateway/bad'])->assertSessionHasErrors('destination_prefixes.0');
        $this->actingAs($admin)->delete('/admin/sip-numbers/'.$number->id)->assertNotFound();
        $this->assertDatabaseCount('number_offers', 0);
    }

    public function test_gateway_changes_invalidate_review_and_review_must_match_settings(): void
    {
        $admin = $this->admin();
        $number = $this->review($admin, $this->stock($admin));
        $version = $this->version($admin);
        $this->actingAs($admin)->put('/sip-gateways/'.$number->provider_gateway_id, ['host' => '192.0.2.20'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->post('/admin/inventory/'.$number->id.'/publish', ['revision' => $number->inventory_revision, 'plan_version_id' => $version->id, 'monthly_amount' => 250000])->assertSessionHasErrors('publication');
        $this->actingAs($admin)->put('/admin/inventory/'.$number->id, ['revision' => $number->inventory_revision, 'enabled' => 1, 'inbound_enabled' => 1, 'outbound_enabled' => 1, 'provider_gateway_id' => $number->provider_gateway_id, 'destination_prefixes_text' => '+989'])->assertSessionHasNoErrors();
        $this->assertNull($number->fresh()->reviewed_at);
    }

    public function test_malformed_gateway_and_stock_relationships_fail_readiness(): void
    {
        $admin = $this->admin();
        $number = $this->stock($admin);
        $gateway = $number->providerGateway;
        $service = app(NumberReadinessService::class);
        $this->assertSame([], $service->issues($number, $gateway, false));
        foreach ([['profile' => 'internal'], ['context' => 'default'], ['verification_status' => 'pending'],
            ['tenant_id' => Tenant::factory()->create()->id], ['approved_for_outbound' => false],
            ['name' => 'arbitrary/sip/url'], ['transport' => 'bad'], ['port' => 0]] as $change) {
            $badGateway = clone $gateway;
            $badGateway->fill($change);
            $this->assertNotEmpty($service->issues($number, $badGateway, false));
        }
        foreach ([['tenant_id' => Tenant::factory()->create()->id], ['inventory_state' => 'quarantined'],
            ['inventory_state' => 'reserved'], ['status' => 'assigned'], ['normalized_number' => '+01234'],
            ['destination_prefixes' => []], ['destination_prefixes' => ['${destination_number}']], ['inbound_enabled' => false]] as $change) {
            $badStock = clone $number;
            $badStock->fill($change);
            $this->assertNotEmpty($service->issues($badStock, $gateway, false));
        }
    }

    public function test_published_stock_does_not_generate_customer_call_routes_or_allow_deletion(): void
    {
        $admin = $this->admin();
        $number = $this->review($admin, $this->stock($admin));
        $version = $this->version($admin);
        app(NumberOfferService::class)->publish($admin, $number->id, $number->inventory_revision, $version->id, 250000);
        $xml = app(FreeSwitchDialplanService::class)->build('public', ['Hunt-Destination-Number' => $number->normalized_number]);
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertStringNotContainsString('application="bridge"', $xml);
        $this->assertStringNotContainsString('application="transfer"', $xml);
        $this->assertSame('monthly', $version->billing_interval);
        $this->expectException(ValidationException::class);
        $number->fresh()->delete();
    }
}
