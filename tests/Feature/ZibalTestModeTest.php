<?php

namespace Tests\Feature;

use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\NumberReservationService;
use App\Services\Commerce\PaidOrderAllocationService;
use App\Services\Commerce\PaymentGatewayService;
use App\Services\Commerce\ZibalPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\MellatFixtures;
use Tests\TestCase;

class ZibalTestModeTest extends TestCase
{
    use MellatFixtures, RefreshDatabase;

    private function sandbox(): array
    {
        [$order, $buyer, $number, $admin] = $this->paymentFixture();
        $service = app(PaymentGatewayService::class);
        $service->update($admin, 'zibal', ['revision' => 0, 'enabled' => true,
            'amount_unit_confirmed' => true, 'mode' => 'test']);
        $service->activate($admin, 'zibal');
        Http::preventStrayRequests();
        Http::fake([
            'gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 123456789]),
            'gateway.zibal.ir/v1/verify' => Http::response(['result' => 100, 'amount' => 2500000]),
        ]);

        return [$order, $buyer, $number, $admin];
    }

    private function start($order, $buyer): PaymentAttempt
    {
        return app(ZibalPaymentService::class)->initiate($buyer, $order->invoice->public_id, (string) Str::uuid(), true);
    }

    private function callbackUrl(PaymentAttempt $attempt, string $success = '1'): string
    {
        return 'https://my.blucom.ir/payments/zibal/callback/'.$attempt->public_id.'?trackId=123456789&success='.$success;
    }

    public function test_sandbox_redirects_and_verifies_without_revenue_or_line_allocation(): void
    {
        [$order, $buyer, $number, $admin] = $this->sandbox();
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/orders/'.$order->public_id)
            ->assertOk()->assertSee('پرداخت آزمایشی — مبلغی کسر نمی‌شود')->assertSee('پرداخت آزمایشی با');
        $attempt = $this->start($order, $buyer);
        $this->assertTrue($attempt->is_test);
        $this->get('https://my.blucom.ir/payments/'.$attempt->public_id)->assertRedirect('https://gateway.zibal.ir/start/123456789');
        Http::assertSent(fn ($request) => $request->url() === 'https://gateway.zibal.ir/v1/request' && $request['merchant'] === 'zibal');
        $this->get($this->callbackUrl($attempt))->assertOk()->assertSee('پرداخت آزمایشی با موفقیت تأیید شد');
        $revision = $number->fresh()->inventory_revision;
        $this->get($this->callbackUrl($attempt))->assertOk();
        $this->assertSame($revision, $number->fresh()->inventory_revision);
        Http::assertSentCount(2);
        $this->assertSame('test_succeeded', $attempt->fresh()->status);
        $this->assertNotNull($attempt->fresh()->verified_at);
        $this->assertNull($attempt->fresh()->settled_at);
        $this->assertNull($order->invoice->fresh()->paid_payment_attempt_id);
        $this->assertNull($order->invoice->fresh()->paid_at);
        $this->assertSame('test_completed', $order->fresh()->status);
        $this->assertSame('test_completed', $order->invoice->fresh()->status);
        $this->assertSame('available', $number->fresh()->inventory_state);
        $this->assertNull($number->fresh()->tenant_id);
        $this->assertNull($number->fresh()->current_reservation_id);
        $this->assertDatabaseCount('number_assignments', 0);
        $this->assertDatabaseCount('number_subscriptions', 0);
        $this->assertDatabaseHas('commerce_audit_events', ['event' => 'payment.test_completed']);
        $this->assertDatabaseMissing('commerce_audit_events', ['event' => 'payment.settled']);
        $this->get('https://my.blucom.ir/orders/'.$order->public_id)->assertOk()
            ->assertSee('آزمایش موفق')->assertDontSee('تنظیم خط و اتصال تلفن')->assertDontSee('name="payment_mode"', false);
        $this->actingAs($admin)->get('https://admin.blucom.ir/admin/payments?mode=test&status=test_succeeded')
            ->assertOk()->assertSee($order->invoice->invoice_number)->assertSee('آزمایش تکمیل‌شده؛ پرداخت نشده');
        $this->get('https://admin.blucom.ir/admin/payments?mode=live')->assertOk()->assertDontSee($order->invoice->invoice_number);
    }

    public function test_failure_returns_to_retry_or_cancel_without_financial_reconciliation(): void
    {
        [$order, $buyer, $number] = $this->sandbox();
        $attempt = $this->start($order, $buyer);
        $this->actingAs($buyer, 'customer')->get($this->callbackUrl($attempt, '0'))->assertOk()
            ->assertSee('پرداخت آزمایشی تکمیل نشد')->assertSee('مبلغی کسر نشده است');
        $this->get('https://my.blucom.ir/orders/'.$order->public_id)->assertOk()
            ->assertSee('ادامهٔ پرداخت آزمایشی با')->assertSee('لغو رزرو شماره');
        $same = $this->start($order, $buyer);
        $this->assertSame($attempt->id, $same->id);
        Http::assertSentCount(1);
        $this->post('https://my.blucom.ir/orders/'.$order->public_id.'/cancel')->assertRedirect();
        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame('cancelled', $order->invoice->fresh()->status);
        $this->assertSame('available', $number->fresh()->inventory_state);
    }

    public function test_mode_rotation_preserves_real_credentials_and_the_old_attempt_mode(): void
    {
        [$order, $buyer, $number, $admin] = $this->sandbox();
        $attempt = $this->start($order, $buyer);
        app(PaymentGatewayService::class)->update($admin, 'zibal', ['revision' => 1, 'enabled' => true,
            'amount_unit_confirmed' => true, 'mode' => 'live', 'merchant_id' => 'synthetic_live']);
        $this->assertFalse(PaymentGateway::where('provider', 'zibal')->first()->currentVersion->isTest());
        $this->get($this->callbackUrl($attempt))->assertOk()->assertSee('پرداخت آزمایشی با موفقیت تأیید شد');
        Http::assertSent(fn ($request) => $request->url() === 'https://gateway.zibal.ir/v1/verify' && $request['merchant'] === 'zibal');
        $this->assertNull($number->fresh()->tenant_id);
        $gateways = app(PaymentGatewayService::class);
        $gateways->update($admin, 'zibal', ['revision' => 2, 'enabled' => true, 'amount_unit_confirmed' => true, 'mode' => 'test']);
        $gateways->update($admin, 'zibal', ['revision' => 3, 'enabled' => true, 'amount_unit_confirmed' => true, 'mode' => 'live']);
        $this->assertSame('synthetic_live', PaymentGateway::where('provider', 'zibal')->first()->currentVersion->zibalMerchant());
    }

    public function test_late_test_callback_cannot_release_another_buyers_hold(): void
    {
        [$order, $buyer, $number] = $this->sandbox();
        $attempt = $this->start($order, $buyer);
        app(NumberReservationService::class)->cancel($buyer, $order->public_id);
        $other = $this->buyer('002');
        $checkout = app(CheckoutService::class);
        $next = $checkout->reserve($other, $number->current_offer_id, $checkout->quote($other, $number->current_offer_id), (string) Str::uuid());
        $this->get($this->callbackUrl($attempt))->assertOk();
        $this->assertSame($next->reservation->id, $number->fresh()->current_reservation_id);
        $this->assertSame('held', $next->reservation->fresh()->status);
        $this->assertSame('reserved', $next->fresh()->status);
        $this->assertNull($number->fresh()->tenant_id);
        $this->assertDatabaseCount('number_assignments', 0);
    }

    public function test_live_attempt_still_verifies_with_real_credentials_after_switching_to_test_mode(): void
    {
        [$order, $buyer, $number, $admin] = $this->sandbox();
        $gateways = app(PaymentGatewayService::class);
        $gateways->update($admin, 'zibal', ['revision' => 1, 'enabled' => true,
            'amount_unit_confirmed' => true, 'mode' => 'live', 'merchant_id' => 'synthetic_live']);
        $attempt = app(ZibalPaymentService::class)->initiate($buyer, $order->invoice->public_id, (string) Str::uuid(), false);
        $gateways->update($admin, 'zibal', ['revision' => 2, 'enabled' => true, 'amount_unit_confirmed' => true, 'mode' => 'test']);
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/orders/'.$order->public_id)
            ->assertOk()->assertDontSee('پرداخت آزمایشی — مبلغی کسر نمی‌شود');
        $this->get($this->callbackUrl($attempt))->assertOk()->assertSee('پرداخت تأیید شد');
        Http::assertSent(fn ($request) => $request->url() === 'https://gateway.zibal.ir/v1/verify' && $request['merchant'] === 'synthetic_live');
        $this->assertFalse($attempt->fresh()->is_test);
        $this->assertSame('settled', $attempt->fresh()->status);
        $this->assertSame($buyer->tenant_id, $number->fresh()->tenant_id);
        $this->assertDatabaseCount('number_assignments', 1);
    }

    public function test_expired_test_reservation_does_not_require_financial_reconciliation(): void
    {
        [$order, $buyer, $number] = $this->sandbox();
        $attempt = $this->start($order, $buyer);
        $this->travel(16)->minutes();
        app(NumberReservationService::class)->expire($order->reservation->id);
        $this->assertSame('expired', $order->fresh()->status);
        $this->assertSame('available', $number->fresh()->inventory_state);
        $this->get($this->callbackUrl($attempt))->assertOk();
        $this->assertSame('test_completed', $order->fresh()->status);
        $this->assertNull($order->invoice->fresh()->paid_payment_attempt_id);
        $this->assertDatabaseCount('number_assignments', 0);
    }

    public function test_mode_changed_since_customer_confirmation_blocks_starting_a_real_payment(): void
    {
        [$order, $buyer, , $admin] = $this->sandbox();
        app(PaymentGatewayService::class)->update($admin, 'zibal', ['revision' => 1, 'enabled' => true,
            'amount_unit_confirmed' => true, 'mode' => 'live', 'merchant_id' => 'synthetic_live']);
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/invoices/'.$order->invoice->public_id.'/payments',
            ['idempotency_key' => (string) Str::uuid(), 'payment_mode' => 'test'])->assertSessionHasErrors('payment');
        Http::assertNothingSent();
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_test_mode_cannot_be_changed_on_an_existing_attempt(): void
    {
        [$order, $buyer] = $this->sandbox();
        $attempt = $this->start($order, $buyer);
        $this->expectException(ValidationException::class);
        $attempt->update(['is_test' => false]);
    }

    public function test_retry_after_switching_to_live_never_promises_a_free_payment(): void
    {
        [$order, $buyer, , $admin] = $this->sandbox();
        $attempt = $this->start($order, $buyer);
        $attempt->update(['status' => 'initiation_failed']);
        app(PaymentGatewayService::class)->update($admin, 'zibal', ['revision' => 1, 'enabled' => true,
            'amount_unit_confirmed' => true, 'mode' => 'live', 'merchant_id' => 'synthetic_live']);
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/orders/'.$order->public_id)
            ->assertOk()->assertDontSee('مبلغی کسر نمی‌شود')->assertSee('value="live"', false);
    }

    public function test_allocation_rejects_test_payment_even_if_financial_fields_were_marked_paid(): void
    {
        [$order, $buyer] = $this->sandbox();
        $attempt = $this->start($order, $buyer);
        $attempt->update(['status' => 'settled', 'verified_at' => now(), 'settled_at' => now()]);
        $order->invoice->update(['status' => 'paid', 'paid_payment_attempt_id' => $attempt->id, 'paid_at' => now()]);
        app(PaidOrderAllocationService::class)->afterSettlement($attempt);
        $this->assertDatabaseCount('number_assignments', 0);
        $this->expectException(ValidationException::class);
        app(PaidOrderAllocationService::class)->allocate($order->id);
    }

    public function test_wrong_amount_on_test_verification_does_not_complete_the_test(): void
    {
        [$order, $buyer, $number] = $this->sandbox();
        $attempt = $this->start($order, $buyer);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake(['gateway.zibal.ir/v1/verify' => Http::response(['result' => 100, 'amount' => 1])]);
        $this->get($this->callbackUrl($attempt))->assertOk()->assertSee('نتیجهٔ پرداخت آزمایشی در حال بررسی است');
        $this->assertSame('unknown', $attempt->fresh()->status);
        $this->assertSame('reserved', $number->fresh()->inventory_state);
        $this->assertNull($order->invoice->fresh()->paid_payment_attempt_id);
    }

    public function test_live_mode_requires_a_real_merchant_and_settings_show_test_mode_without_secrets(): void
    {
        [, , , $admin] = $this->sandbox();
        $this->actingAs($admin)->get('https://admin.blucom.ir/admin/settings/payment-gateways')
            ->assertOk()->assertSee('حالت ذخیره‌شده: آزمایشی');
        $fields = ['revision' => 1, 'enabled' => 1, 'amount_unit_confirmed' => 1, 'mode' => 'live'];
        $this->put('https://admin.blucom.ir/admin/settings/payment-gateways/zibal', $fields)->assertSessionHasErrors('gateway');
        $this->put('https://admin.blucom.ir/admin/settings/payment-gateways/zibal', [...$fields, 'merchant_id' => 'zibal'])
            ->assertSessionHasErrors('gateway');
        $this->assertTrue(PaymentGateway::where('provider', 'zibal')->first()->currentVersion->isTest());
    }
}
