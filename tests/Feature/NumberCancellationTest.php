<?php

namespace Tests\Feature;

use App\Contracts\MellatClient;
use App\Models\NumberAssignment;
use App\Models\NumberSubscription;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\User;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\LineEntitlementService;
use App\Services\Commerce\MellatPaymentService;
use App\Services\Commerce\NumberInventoryService;
use App\Services\Commerce\NumberOfferService;
use App\Services\Commerce\PaidOrderAllocationService;
use App\Services\FreeSwitchDialplanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeMellatClient;
use Tests\Support\MellatFixtures;
use Tests\TestCase;

class NumberCancellationTest extends TestCase
{
    use MellatFixtures, RefreshDatabase;

    private function allocated(): array
    {
        [$order, $buyer, $number, $admin] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $payments->callback($attempt->public_id, $this->callbackPayload($attempt));

        return [$order->fresh(), $buyer, $number->fresh(), $admin];
    }

    private function data($number): array
    {
        return ['assignment_id' => $number->current_assignment_id, 'revision' => $number->inventory_revision,
            'reason' => 'Synthetic service cancellation reason', 'refund_decision' => 'refund_pending', 'confirm' => 1];
    }

    public function test_cancel_then_return_preserves_payment_and_extensions_and_requires_new_review(): void
    {
        [$order, $buyer, $number, $admin] = $this->allocated();
        $invoiceBefore = $order->invoice->getAttributes();
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/lines/'.$number->id.'/answer',
            ['answerer' => 'new', 'display_name' => 'Synthetic phone'])->assertSessionHasNoErrors();
        $phone = SipExtension::firstOrFail();
        $this->post('https://my.blucom.ir/lines/'.$number->id.'/outbound', ['enabled' => 1, 'extension_ids' => [$phone->id]])->assertSessionHasNoErrors();
        $number = $number->fresh();
        $data = $this->data($number);
        $this->actingAs($admin, 'web')->get('https://admin.blucom.ir/admin/numbers/'.$number->id.'/cancellation')->assertOk()->assertSee($order->invoice->invoice_number);
        $url = 'https://admin.blucom.ir/admin/numbers/'.$number->id.'/cancel-service';
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($url, $data)->assertRedirect()->assertSessionHasNoErrors();
        $number = $number->fresh();
        $this->assertNull($number->tenant_id);
        $this->assertSame('quarantined', $number->inventory_state);
        $this->assertFalse($number->enabled);
        $this->assertFalse(app(LineEntitlementService::class)->allows($number, false));
        $this->assertSame('cancelled', NumberSubscription::firstOrFail()->status);
        $this->assertSame('service_cancelled', $order->fresh()->status);
        $this->assertSame($invoiceBefore, $order->invoice->fresh()->getAttributes());
        $this->assertDatabaseCount('sip_extensions', 1);
        $this->assertDatabaseCount('inbound_routes', 0);
        $this->assertDatabaseCount('outbound_routes', 0);
        $dialplan = app(FreeSwitchDialplanService::class);
        $this->assertStringNotContainsString('inbound_'.$number->id, $dialplan->build('public', ['destination_number' => $number->number]));
        $this->assertStringNotContainsString('sofia/gateway/', $dialplan->build('default', ['variable_sip_auth_username' => $phone->extension, 'destination_number' => '09123456789']));

        $this->assertSame(1, DB::table('commerce_audit_events')->where('event', 'service.cancelled')->count());
        $this->assertNotNull(NumberAssignment::firstOrFail()->released_at);
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/lines/'.$number->id)->assertNotFound();
        $this->get('https://my.blucom.ir/orders/'.$order->public_id)->assertOk()->assertSee('سرویس این شماره لغو شد');
        $this->actingAs($admin, 'web')->get('https://admin.blucom.ir/admin/numbers/'.$number->id.'/cancellation')->assertOk()->assertSee('بازپرداخت نیازمند پیگیری جداگانه');
        $returnData = $this->data($number);
        $returnUrl = 'https://admin.blucom.ir/admin/numbers/'.$number->id.'/return-to-stock';
        $this->post($returnUrl, $returnData)->assertRedirect()->assertSessionHasNoErrors();
        $this->post($returnUrl, $returnData)->assertRedirect()->assertSessionHasNoErrors();
        $number = $number->fresh();
        $this->assertSame('available', $number->inventory_state);
        $this->assertNull($number->current_assignment_id);
        $this->assertNull($number->current_offer_id);
        $this->assertNull($number->readiness_fingerprint);
        $this->assertSame(1, DB::table('commerce_audit_events')->where('event', 'service.returned_to_stock')->count());
        $this->get('https://admin.blucom.ir/admin/inventory/'.$number->id)->assertOk();
        $old = app(PaidOrderAllocationService::class)->allocate($order->id);
        $this->assertNotNull($old->released_at);
        $this->assertNull($number->fresh()->tenant_id);
        // Existing stock preparation can publish a fresh offer after review.
        $inventory = app(NumberInventoryService::class);
        $number = $inventory->change($admin, $number->id, $number->inventory_revision, 'update', [
            'provider_gateway_id' => $number->provider_gateway_id, 'enabled' => true, 'inbound_enabled' => true,
            'outbound_enabled' => true, 'destination_prefixes' => ['+989'],
        ]);
        $inventory->review($admin, $number->id, $number->inventory_revision, 'Synthetic new technical review');
        $number = $number->fresh();
        $offer = app(NumberOfferService::class)->publish($admin, $number->id, $number->inventory_revision, $order->item->plan_version_id, 250000);
        $this->assertNotNull($offer->id);
        $newBuyer = $this->buyer('002');
        $checkout = app(CheckoutService::class);
        $newOrder = $checkout->reserve($newBuyer, $offer->id, $checkout->quote($newBuyer, $offer->id), (string) Str::uuid());
        $this->app->instance(MellatClient::class, new FakeMellatClient(['0,SyntheticNewRef', '0', '0']));
        $payments = app(MellatPaymentService::class);
        $newAttempt = $payments->initiate($newBuyer, $newOrder->invoice->public_id, (string) Str::uuid());
        $payments->callback($newAttempt->public_id, $this->callbackPayload($newAttempt, '7654322'));
        $this->assertSame($newBuyer->tenant_id, $number->fresh()->tenant_id);
        // Old retries and late allocation cannot touch the next customer's line.
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($returnUrl, $returnData)->assertSessionHasNoErrors();
        app(PaidOrderAllocationService::class)->allocate($order->id);
        $this->assertSame($newBuyer->tenant_id, $number->fresh()->tenant_id);
        $this->assertSame('assigned', $number->fresh()->inventory_state);
        $this->post($url, array_replace($this->data($number->fresh()), ['assignment_id' => $data['assignment_id']]))->assertSessionHasNoErrors();
        $this->assertSame($newBuyer->tenant_id, $number->fresh()->tenant_id);

    }

    public function test_stale_or_wrong_assignment_and_missing_confirmation_cannot_cancel(): void
    {
        [$order, $buyer, $number, $admin] = $this->allocated();
        $url = 'https://admin.blucom.ir/admin/numbers/'.$number->id.'/cancel-service';
        $this->actingAs($admin, 'web');
        $this->post($url, array_replace($this->data($number), ['revision' => $number->inventory_revision - 1]))->assertSessionHasErrors();
        $this->post($url, array_replace($this->data($number), ['confirm' => 0]))->assertSessionHasErrors('confirm');
        $this->post($url, array_replace($this->data($number), ['refund_decision' => 'refunded']))->assertSessionHasErrors('refund_decision');
        $this->post('https://admin.blucom.ir/admin/numbers/'.$number->id.'/return-to-stock', $this->data($number))->assertSessionHasErrors('cancellation');
        $this->assertSame('assigned', $number->fresh()->inventory_state);
        $this->assertNull(NumberAssignment::firstOrFail()->released_at);
        $otherNumber = SipNumber::factory()->create();
        $this->post('https://admin.blucom.ir/admin/numbers/'.$otherNumber->id.'/cancel-service', $this->data($number))->assertSessionHasErrors('cancellation');
        $admin->update(['disabled_at' => now()]);
        $this->post($url, $this->data($number))->assertForbidden();
        $this->assertSame('assigned', $number->fresh()->inventory_state);

    }

    public function test_customer_and_non_admin_cannot_use_admin_cancellation(): void
    {
        [, $buyer, $number] = $this->allocated();
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/admin/numbers/'.$number->id.'/cancel-service', $this->data($number))->assertNotFound();
        $this->actingAs(User::factory()->create(), 'web')
            ->post('https://admin.blucom.ir/admin/numbers/'.$number->id.'/cancel-service', $this->data($number))->assertForbidden();
        $this->assertSame('assigned', $number->fresh()->inventory_state);
    }
}
