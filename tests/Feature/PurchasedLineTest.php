<?php

namespace Tests\Feature;

use App\Models\CallQueue;
use App\Models\NumberSubscription;
use App\Models\SipExtension;
use App\Services\CallQueueConfigService;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\LineEntitlementService;
use App\Services\Commerce\MellatPaymentService;
use App\Services\Commerce\NumberReservationService;
use App\Services\Commerce\PaidOrderAllocationService;
use App\Services\FreeSwitchDialplanService;
use App\Services\FreeSwitchDirectoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MellatFixtures;
use Tests\TestCase;

class PurchasedLineTest extends TestCase
{
    use MellatFixtures, RefreshDatabase;

    private function paid(): array
    {
        [$order, $buyer, $number, $admin] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $payments->callback($attempt->public_id, $this->callbackPayload($attempt));

        return [$order->fresh(), $buyer, $number->fresh(), $admin, $attempt];
    }

    public function test_customer_configures_paid_line_and_first_save_starts_period_once(): void
    {
        [$order, $buyer, $number] = $this->paid();
        $this->assertFalse(app(LineEntitlementService::class)->allows($number));
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/lines/'.$number->id)->assertOk();
        $this->post('https://my.blucom.ir/lines/'.$number->id.'/answer', ['answerer' => 'new', 'display_name' => 'پاسخ‌گو'])
            ->assertRedirect()->assertSessionHas('phone_credentials');
        $subscription = NumberSubscription::firstOrFail();
        $this->assertSame('active', $subscription->status);
        $this->assertTrue(app(LineEntitlementService::class)->allows($number->fresh()));
        $end = $subscription->period_ends_at;
        $phone = SipExtension::firstOrFail();
        $this->travel(1)->days();
        $this->post('https://my.blucom.ir/lines/'.$number->id.'/answer', ['answerer' => 'existing', 'extension_id' => $phone->id])->assertRedirect();
        $this->assertEquals($end, $subscription->fresh()->period_ends_at);
        $this->assertDatabaseCount('number_assignments', 1);
        $this->assertDatabaseCount('number_subscriptions', 1);
    }

    public function test_customer_chooses_extension_for_new_answerer_and_additional_phone(): void
    {
        [, $buyer, $number] = $this->paid();
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/lines/'.$number->id.'/answer', [
            'answerer' => 'new', 'display_name' => 'Reception', 'extension' => '301',
        ])->assertRedirect('https://my.blucom.ir/lines/'.$number->id.'#phones')
            ->assertSessionHasNoErrors()->assertSessionHas('phone_credentials.extension', '301');
        $phone = SipExtension::query()->where('extension', '301')->firstOrFail();
        $this->assertSame($buyer->tenant_id, $phone->tenant_id);
        $this->assertSame($phone->id, $number->fresh()->inboundRoute->destination_id);
        $this->assertNull($phone->outboundRoute);

        $this->post('https://my.blucom.ir/lines/'.$number->id.'/phones', [
            'display_name' => 'Sales', 'extension' => '۳۰۲',
        ])->assertSessionHasNoErrors()->assertSessionHas('phone_credentials.extension', '302');
        $this->assertSame($phone->id, $number->fresh()->inboundRoute->destination_id);
        $this->assertDatabaseHas('sip_extensions', ['tenant_id' => $buyer->tenant_id, 'extension' => '302']);
        $this->assertDatabaseCount('outbound_routes', 0);
        $this->get('https://my.blucom.ir/lines/'.$number->id)->assertOk()
            ->assertSee('data-guide-device', false)->assertSee('Yealink')->assertSee('Register Name')
            ->assertSee('data-toggle-password', false);
    }

    public function test_duplicate_extension_is_rejected_without_disclosing_other_tenant_or_activating_line(): void
    {
        [, $buyer, $number] = $this->paid();
        $foreign = SipExtension::factory()->create(['extension' => '345', 'display_name' => 'Private tenant person', 'enabled' => false]);
        $this->assertNotSame($buyer->tenant_id, $foreign->tenant_id);
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/lines/'.$number->id)->assertOk();
        $this->followingRedirects()->from('https://my.blucom.ir/lines/'.$number->id)
            ->post('https://my.blucom.ir/lines/'.$number->id.'/answer', [
                'answerer' => 'new', 'display_name' => 'Reception', 'extension' => '345', 'line_form' => 'answer',
            ])->assertOk()->assertSee('value="345"', false)->assertSee('aria-invalid="true"', false)
            ->assertDontSee('Private tenant person')->assertDontSee($foreign->password_encrypted);
        $this->assertDatabaseCount('sip_extensions', 1);
        $this->assertDatabaseCount('inbound_routes', 0);
        $this->assertSame('pending_activation', NumberSubscription::firstOrFail()->status);
        $this->post('https://my.blucom.ir/lines/'.$number->id.'/phones', [
            'display_name' => 'Sales', 'extension' => '٣٤٥', 'line_form' => 'phone',
        ])->assertSessionHasErrors('extension');
        $this->assertDatabaseCount('sip_extensions', 1);
    }

    public function test_invalid_extension_numbers_never_create_an_extension_or_route(): void
    {
        [, $buyer, $number] = $this->paid();
        $this->actingAs($buyer, 'customer');
        foreach (['12', '0123', '1234567890', '300@evil.example', '3e2', '301;bridge', '-301'] as $extension) {
            $this->post('https://my.blucom.ir/lines/'.$number->id.'/phones', [
                'display_name' => 'Sales', 'extension' => $extension,
            ])->assertSessionHasErrors('extension');
        }
        $this->assertDatabaseCount('sip_extensions', 0);
        $this->assertDatabaseCount('outbound_routes', 0);
    }

    public function test_default_extension_is_predictable_and_skips_globally_reserved_numbers(): void
    {
        [, $buyer, $number] = $this->paid();
        SipExtension::factory()->create(['extension' => '2000', 'enabled' => false]);
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/lines/'.$number->id)
            ->assertOk()->assertSee('value="2001"', false);
        $this->post('https://my.blucom.ir/lines/'.$number->id.'/phones', ['display_name' => 'Reception'])
            ->assertSessionHasNoErrors()->assertSessionHas('phone_credentials.extension', '2001');
        $this->get('https://my.blucom.ir/lines/'.$number->id)->assertSee('value="2002"', false);
    }

    public function test_paid_line_authorizes_only_its_tenant_and_permitted_outbound_destinations(): void
    {
        [, $buyer, $number] = $this->paid();
        $other = $this->buyer('002');
        $this->actingAs($other, 'customer')->get('https://my.blucom.ir/lines/'.$number->id)->assertNotFound();
        $this->post('https://my.blucom.ir/lines/'.$number->id.'/answer', ['answerer' => 'new', 'display_name' => 'intruder'])->assertNotFound();
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/lines/'.$number->id.'/answer', ['answerer' => 'new', 'display_name' => 'پاسخ‌گو'])->assertRedirect();
        $phone = SipExtension::firstOrFail();
        $this->post('https://my.blucom.ir/lines/'.$number->id.'/outbound', ['enabled' => 1, 'extension_ids' => [$phone->id]])->assertRedirect();
        $request = ['variable_sip_auth_username' => $phone->extension, 'destination_number' => '09123456789'];
        $xml = app(FreeSwitchDialplanService::class)->build('default', $request);
        $this->assertStringContainsString('sofia/gateway/', $xml);
        $this->assertStringContainsString('+989123456789', $xml);
        foreach (['+12025550123', 'sofia/gateway/evil', '+989123456789;evil'] as $destination) {
            $xml = app(FreeSwitchDialplanService::class)->build('default', [...$request, 'destination_number' => $destination]);
            $this->assertStringNotContainsString('sofia/gateway/', $xml);
        }
        $this->travel(32)->days();
        $this->assertFalse(app(LineEntitlementService::class)->allows($number->fresh()));
        $this->assertStringNotContainsString('sofia/gateway/', app(FreeSwitchDialplanService::class)->build('default', $request));
        $this->assertStringNotContainsString('password', app(FreeSwitchDirectoryService::class)->buildAll($phone->extension));
        $this->assertStringNotContainsString('inbound_'.$number->id, app(FreeSwitchDialplanService::class)->build('public', ['destination_number' => $number->number]));
    }

    public function test_pending_activation_allows_phone_registration_but_not_calls_and_limits_are_enforced(): void
    {
        [, $buyer, $number] = $this->paid();
        $this->actingAs($buyer, 'customer');
        for ($i = 0; $i < 5; $i++) {
            $this->post('https://my.blucom.ir/lines/'.$number->id.'/phones', ['display_name' => 'Phone '.$i])->assertRedirect()->assertSessionHasNoErrors();
        }
        $this->post('https://my.blucom.ir/lines/'.$number->id.'/phones', ['display_name' => 'Overflow'])->assertSessionHasErrors('line');
        $phone = SipExtension::firstOrFail();
        $this->assertStringContainsString('password', app(FreeSwitchDirectoryService::class)->buildAll($phone->extension));
        $this->assertFalse(app(LineEntitlementService::class)->allows($number));
        $this->assertDatabaseCount('sip_extensions', 5);
    }

    public function test_expired_paid_hold_requires_explicit_repair_and_never_steals_new_hold(): void
    {
        [$order, $buyer, $number, $admin] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->travel(16)->minutes();
        app(NumberReservationService::class)->expire($order->reservation->id);
        $payments->callback($attempt->public_id, $this->callbackPayload($attempt));
        $this->assertSame('paid_unfulfilled', $order->fresh()->status);
        $allocation = app(PaidOrderAllocationService::class);
        $this->assertNull($allocation->allocate($order->id));
        $assignment = $allocation->allocate($order->id, $admin, 'Customer requested repair of settled test order');
        $this->assertNotNull($assignment);
        $this->assertSame($assignment->id, $allocation->allocate($order->id)->id);
        $this->assertDatabaseCount('number_assignments', 1);
    }

    public function test_active_inbound_bridge_does_not_transfer_provider_calls_to_default(): void
    {
        [, $buyer, $number] = $this->paid();
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/lines/'.$number->id.'/answer', ['answerer' => 'new', 'display_name' => 'Answer'])->assertRedirect();
        $xml = app(FreeSwitchDialplanService::class)->build('public', ['destination_number' => $number->number]);
        $this->assertStringContainsString('inbound_'.$number->id, $xml);
        $this->assertStringContainsString('application="bridge"', $xml);
        $this->assertStringNotContainsString('XML default', $xml);
        $this->assertStringContainsString('blucom_assignment_id='.$number->current_assignment_id, $xml);
        NumberSubscription::firstOrFail()->update(['status' => 'suspended']);
        $this->assertStringNotContainsString('inbound_'.$number->id, app(FreeSwitchDialplanService::class)->build('public'));
    }

    public function test_customer_can_create_a_team_inline_and_configuration_lookup_is_scoped(): void
    {
        config(['voip.queues_enabled' => true]);
        [, $buyer, $number] = $this->paid();
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/lines/'.$number->id.'/phones', ['display_name' => 'Answer'])->assertRedirect();
        $phone = SipExtension::firstOrFail();
        $this->post('https://my.blucom.ir/lines/'.$number->id.'/answer', ['answerer' => 'create_team', 'team_name' => 'Support', 'member_ids' => [$phone->id]])->assertRedirect()->assertSessionHasNoErrors();
        $queue = CallQueue::firstOrFail();
        $this->assertDatabaseHas('inbound_routes', ['sip_number_id' => $number->id, 'destination_type' => 'queue', 'destination_id' => $queue->id]);
        $config = app(CallQueueConfigService::class);
        $xml = $config->lookup(['key_value' => 'callcenter.conf', 'CC-Queue' => $queue->freeSwitchName()]);
        $this->assertStringContainsString('section name="configuration"', $xml);
        $this->assertStringContainsString($queue->freeSwitchName(), $xml);
        $this->assertStringNotContainsString($queue->freeSwitchName(), $config->lookup(['key_value' => 'sofia.conf']));
        NumberSubscription::firstOrFail()->update(['status' => 'suspended']);
        $this->assertStringNotContainsString($queue->freeSwitchName(), $config->lookup(['key_value' => 'callcenter.conf']));
    }

    public function test_allocation_failure_preserves_settlement_and_worker_recovers_without_payment_retry(): void
    {
        [$order, $buyer, $number] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $allocator = \Mockery::mock(PaidOrderAllocationService::class)->makePartial();
        $allocator->shouldReceive('allocate')->once()->andThrow(new \RuntimeException('Synthetic allocation outage'));
        $this->app->instance(PaidOrderAllocationService::class, $allocator);
        $this->assertSame('settled', $payments->callback($attempt->public_id, $this->callbackPayload($attempt))->status);
        $this->assertSame('paid', $order->invoice->fresh()->status);
        $this->assertSame('paid_pending_allocation', $order->fresh()->status);
        $this->assertNull($number->fresh()->tenant_id);
        $this->app->forgetInstance(PaidOrderAllocationService::class);
        $this->artisan('commerce:allocate-paid-orders')->assertExitCode(0);
        $this->assertSame('allocated', $order->fresh()->status);
        $this->assertDatabaseCount('number_assignments', 1);
        $this->artisan('commerce:allocate-paid-orders')->assertExitCode(0);
        $this->assertDatabaseCount('number_subscriptions', 1);
    }

    public function test_admin_repair_does_not_take_a_new_customer_reservation(): void
    {
        [$order, $buyer, $number, $admin] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->travel(16)->minutes();
        app(NumberReservationService::class)->expire($order->reservation->id);
        $other = $this->buyer('002');
        $checkout = app(CheckoutService::class);
        $offerId = $order->item->number_offer_id;
        $newOrder = $checkout->reserve($other, $offerId, $checkout->quote($other, $offerId), (string) Str::uuid());
        $payments->callback($attempt->public_id, $this->callbackPayload($attempt));
        $this->assertNull(app(PaidOrderAllocationService::class)->allocate($order->id, $admin, 'Synthetic review of late payment conflict'));
        $this->assertSame($newOrder->reservation->id, $number->fresh()->current_reservation_id);
        $this->assertNull($number->fresh()->tenant_id);
        $this->assertDatabaseCount('number_assignments', 0);
        $this->assertSame('paid', $order->invoice->fresh()->status);
    }
}
