<?php

namespace Tests\Feature;

use App\Models\PaymentAttempt;
use App\Services\Commerce\CustomerCommercePresenter;
use App\Services\Commerce\NumberReservationService;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\PaymentGatewayService;
use App\Services\Commerce\ZibalPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\MellatFixtures;
use Tests\TestCase;

class ZibalCheckoutAndReservationTest extends TestCase
{
    use MellatFixtures, RefreshDatabase;

    private function zibalFixture(array $requestResponse = ['result' => 100, 'trackId' => 4836467990], array $verifyResponse = ['result' => 100, 'amount' => 2500000, 'refNumber' => '7654321']): array
    {
        [$order, $buyer, $number, $admin] = $this->paymentFixture();
        $gateways = app(PaymentGatewayService::class);
        $gateways->update($admin, 'zibal', ['revision' => 0, 'enabled' => true,
            'amount_unit_confirmed' => true, 'merchant_id' => 'synthetic_zibal']);
        $gateways->activate($admin, 'zibal');
        Http::preventStrayRequests();
        Http::fake([
            'gateway.zibal.ir/v1/request' => Http::response($requestResponse),
            'gateway.zibal.ir/v1/verify' => Http::response($verifyResponse),
        ]);

        return [$order, $buyer, $number, $admin];
    }

    public function test_ready_zibal_payment_redirects_to_gateway_and_callback_records_payment_once(): void
    {
        [$order, $buyer, $number] = $this->zibalFixture();
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/invoices/'.$order->invoice->public_id.'/payments',
            ['idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $attempt = PaymentAttempt::firstOrFail();
        $this->assertSame('redirect_ready', $attempt->status);
        $this->get('https://my.blucom.ir/payments/'.$attempt->public_id)
            ->assertRedirect('https://gateway.zibal.ir/start/4836467990');
        $this->assertNull($order->invoice->fresh()->paid_payment_attempt_id);
        Http::assertSent(fn ($request) => $request->url() === 'https://gateway.zibal.ir/v1/request'
            && $request['amount'] === 2500000 && $request['merchant'] === 'synthetic_zibal'
            && str_contains($request['callbackUrl'], '/payments/zibal/callback/'));
        $url = 'https://my.blucom.ir/payments/zibal/callback/'.$attempt->public_id.'?trackId=4836467990&success=1';
        $this->get($url)->assertOk()->assertSee('پرداخت تأیید شد');
        $this->get($url)->assertOk();
        $this->assertSame('settled', $attempt->fresh()->status);
        $this->assertSame($buyer->tenant_id, $number->fresh()->tenant_id);
        $this->assertDatabaseCount('number_assignments', 1);
        Http::assertSentCount(2);
    }

    public function test_gateway_failure_does_not_redirect_or_claim_payment(): void
    {
        [$order, $buyer] = $this->zibalFixture(['result' => 103]);
        $attempt = app(ZibalPaymentService::class)->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/payments/'.$attempt->public_id)
            ->assertOk()->assertSee('اتصال به درگاه برای شروع پرداخت برقرار نشد');
        $this->assertSame('initiation_failed', $attempt->status);
        $this->assertNull($order->invoice->fresh()->paid_payment_attempt_id);
    }

    public function test_customer_cancels_unpaid_reservation_idempotently_and_stock_is_released(): void
    {
        [$order, $buyer, $number] = $this->zibalFixture();
        $url = 'https://my.blucom.ir/orders/'.$order->public_id.'/cancel';
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/orders/'.$order->public_id)
            ->assertOk()->assertSee('لغو رزرو شماره');
        $this->post($url)->assertRedirect();
        $revision = $number->fresh()->inventory_revision;
        $this->post($url)->assertRedirect();
        $this->assertSame($revision, $number->fresh()->inventory_revision);
        $this->assertSame('available', $number->fresh()->inventory_state);
        $this->assertNull($number->fresh()->current_reservation_id);
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $order->invoice->fresh()->status);
        $this->get('https://my.blucom.ir/orders/'.$order->public_id)->assertOk()->assertSee('رزرو این شماره لغو شد');
        $this->assertDatabaseCount('commerce_orders', 1);
        Http::assertNothingSent();
    }

    public function test_customer_cannot_cancel_another_tenants_reservation(): void
    {
        [$order, , $number] = $this->zibalFixture();
        $other = $this->buyer('002');
        $this->actingAs($other, 'customer')->post('https://my.blucom.ir/orders/'.$order->public_id.'/cancel')->assertNotFound();
        $this->assertSame('reserved', $number->fresh()->inventory_state);
    }

    public function test_admin_can_release_unresolved_hold_with_audit_and_late_payment_is_preserved(): void
    {
        [$order, $buyer, $number, $admin] = $this->zibalFixture();
        $attempt = app(ZibalPaymentService::class)->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->assertSame('reserved', $number->fresh()->inventory_state);
        $this->actingAs($admin, 'web')->get('https://admin.blucom.ir/admin/reservations')
            ->assertOk()->assertSee('لغو رزرو و آزادسازی شماره');
        $this->post('https://admin.blucom.ir/admin/reservations/'.$order->public_id.'/cancel',
            ['reason' => 'Synthetic operator releases unresolved hold'])->assertRedirect();
        $this->assertSame('reconciliation_required', $order->fresh()->status);
        $this->assertSame('redirect_ready', $attempt->fresh()->status);
        $this->assertSame('available', $number->fresh()->inventory_state);
        $this->assertDatabaseHas('commerce_audit_events', ['event' => 'reservation.cancelled', 'actor_user_id' => $admin->id]);
        $settled = app(ZibalPaymentService::class)->callback($attempt->public_id, ['trackId' => '4836467990', 'success' => '1']);
        $this->assertSame('settled', $settled->status);
        $this->assertSame('paid_unfulfilled', $order->fresh()->status);
        $this->assertNull($number->fresh()->tenant_id);
        $this->assertDatabaseCount('number_assignments', 0);
    }


    public function test_customer_can_cancel_waiting_payment_but_late_payment_is_retained(): void
    {
        [$order, $buyer, $number] = $this->zibalFixture();
        $attempt = app(ZibalPaymentService::class)->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/orders/'.$order->public_id.'/cancel')->assertRedirect();
        $this->assertSame('reconciliation_required', $order->fresh()->status);
        $this->assertSame('available', $number->fresh()->inventory_state);
        $this->get('https://my.blucom.ir/payments/'.$attempt->public_id)->assertOk();
        $other = $this->buyer('002');
        $checkout = app(CheckoutService::class);
        $newOrder = $checkout->reserve($other, $number->current_offer_id, $checkout->quote($other, $number->current_offer_id), (string) Str::uuid());
        app(NumberReservationService::class)->cancel($buyer, $order->public_id);
        $this->assertSame($newOrder->reservation->id, $number->fresh()->current_reservation_id);
        app(ZibalPaymentService::class)->callback($attempt->public_id, ['trackId' => '4836467990', 'success' => '1']);
        $this->assertSame('paid_unfulfilled', $order->fresh()->status);
        $this->assertNull($number->fresh()->tenant_id);
    }

    public function test_customer_cannot_cancel_while_gateway_operation_is_processing(): void
    {
        [$order, $buyer, $number] = $this->zibalFixture();
        $attempt = app(ZibalPaymentService::class)->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $attempt->update(['operation_token' => (string) Str::uuid(), 'operation_expires_at' => now()->addMinute(), 'status' => 'verifying']);
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/orders/'.$order->public_id.'/cancel')->assertSessionHasErrors('reservation');
        $this->assertSame('reserved', $number->fresh()->inventory_state);
    }


    public function test_ip_rejection_does_not_look_paid_and_legacy_attempt_can_retry_without_a_gateway_link(): void
    {
        [$order, $buyer] = $this->zibalFixture(['result' => 115, 'message' => 'invalid IP']);
        $payments = app(ZibalPaymentService::class);
        $oldKey = (string) Str::uuid();
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, $oldKey);
        $this->assertSame('initiation_failed', $attempt->status);
        $this->assertSame('115', $attempt->last_code);
        $this->assertNull($attempt->ref_id);
        $attempt->update(['status' => 'unknown']); // History written by the older implementation.
        $view = app(CustomerCommercePresenter::class)->order($order->fresh(), $buyer);
        $this->assertTrue($view['canStart']);
        $this->assertSame('پرداخت آغاز نشد', $view['badge']);
        $this->assertSame($attempt->id, $payments->initiate($buyer, $order->invoice->public_id, $oldKey)->id);
        Http::assertSentCount(1);
        Http::swap(new \Illuminate\Http\Client\Factory());
        Http::preventStrayRequests();
        Http::fake(['gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 123456789])]);
        $retry = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->assertNotSame($attempt->id, $retry->id);
        $this->assertSame('redirect_ready', $retry->status);
        $this->assertSame('initiation_failed', $attempt->fresh()->status);
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/payments/'.$retry->public_id)
            ->assertRedirect('https://gateway.zibal.ir/start/123456789');
        $this->assertNull($order->invoice->fresh()->paid_payment_attempt_id);
    }

    public function test_unknown_attempt_with_a_gateway_link_never_allows_a_second_charge(): void
    {
        [$order, $buyer] = $this->zibalFixture();
        $payments = app(ZibalPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $attempt->update(['status' => 'unknown']);
        $this->assertFalse($attempt->hasUndeliveredZibalInitiation());
        $this->assertFalse(app(CustomerCommercePresenter::class)->order($order->fresh(), $buyer)['canStart']);
        $this->assertSame($attempt->id, $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid())->id);
        Http::assertSentCount(1);
    }

    public function test_wrong_verified_amount_never_marks_invoice_paid(): void
    {
        [$order, $buyer] = $this->zibalFixture(verifyResponse: ['result' => 100, 'amount' => 1]);
        $attempt = app(ZibalPaymentService::class)->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $result = app(ZibalPaymentService::class)->callback($attempt->public_id, ['trackId' => '4836467990', 'success' => '1']);
        $this->assertSame('unknown', $result->status);
        $this->assertNull($order->invoice->fresh()->paid_payment_attempt_id);
    }

    public function test_unpaid_gateway_link_cannot_authorize_a_second_charge(): void
    {
        [$order, $buyer] = $this->zibalFixture(verifyResponse: ['result' => 202, 'status' => -1]);
        $payments = app(ZibalPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $payments->callback($attempt->public_id, ['trackId' => '4836467990', 'success' => '1']);
        $retry = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->assertSame($attempt->id, $retry->id);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertTrue(app(CustomerCommercePresenter::class)->order($order->fresh(), $buyer)['canCancel']);
    }
}
