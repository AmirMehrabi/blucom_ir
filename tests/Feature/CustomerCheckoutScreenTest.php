<?php

namespace Tests\Feature;

use App\Contracts\MellatClient;
use App\Exceptions\PaymentTransportException;
use App\Models\CommerceOrder;
use App\Models\PaymentAttempt;
use App\Services\Commerce\NumberOfferService;
use App\Services\Commerce\PaymentGatewayService;
use App\Services\CustomerAccountService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\FakeMellatClient;
use Tests\Support\MellatFixtures;
use Tests\TestCase;

class CustomerCheckoutScreenTest extends TestCase
{
    use MellatFixtures, RefreshDatabase;

    private const HOST = 'https://my.blucom.ir';

    private function screens(array $responses = ['0,SyntheticCaseRef', '0', '0']): array
    {
        config(['commerce.catalog_enabled' => true, 'commerce.reservation_enabled' => true, 'commerce.checkout_enabled' => true]);
        [$offer, $number, $admin] = $this->offer();
        $buyer = $this->buyer();
        app(PaymentGatewayService::class)->update($admin, 'mellat', [
            'revision' => 0, 'enabled' => true, 'amount_unit_confirmed' => true,
            'merchant_terminal_id' => '123456', 'merchant_username' => 'synthetic-merchant', 'merchant_password' => 'synthetic-test-only',
        ]);
        $fake = new FakeMellatClient($responses);
        $this->app->instance(MellatClient::class, $fake);

        return [$offer, $buyer, $number, $admin, $fake];
    }

    private function reserveThroughScreen($offer, $buyer): CommerceOrder
    {
        $review = $this->actingAs($buyer, 'customer')->get(self::HOST.'/numbers/'.$offer->id.'/checkout')->assertOk();
        $fields = [...$review->viewData('quote'), 'confirmed' => '1', 'idempotency_key' => $review->viewData('key')];
        $this->post(self::HOST.'/orders', $fields)->assertRedirect();
        $order = CommerceOrder::query()->latest('id')->firstOrFail();
        $this->post(self::HOST.'/orders', $fields)->assertRedirect(self::HOST.'/orders/'.$order->public_id);
        $this->assertDatabaseCount('commerce_orders', 1);

        return $order;
    }

    public function test_customer_can_browse_review_reserve_pay_and_read_a_paid_order_before_activation(): void
    {
        [$offer, $buyer, $number, , $fake] = $this->screens();
        $this->actingAs($buyer, 'customer')->get(self::HOST.'/numbers')->assertOk()
            ->assertSee('۲۵۰٬۰۰۰')->assertSee('تومان')->assertSee('شمارهٔ کسب‌وکارتان')
            ->assertDontSee('synthetic-merchant')->assertDontSee('synthetic-test-only');
        $order = $this->reserveThroughScreen($offer, $buyer);
        $show = $this->get(self::HOST.'/orders/'.$order->public_id)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $show->assertSee('پرداخت با بانک ملت')->assertSee('زمان باقی‌مانده')->assertSee('۱۴۰۵');
        $this->post(self::HOST.'/invoices/'.$order->invoice->public_id.'/payments', ['idempotency_key' => $show->viewData('key')])->assertRedirect();
        $attempt = PaymentAttempt::firstOrFail();
        $this->get(self::HOST.'/payments/'.$attempt->public_id)->assertOk()->assertSee('ورود به درگاه بانک ملت')->assertSee($attempt->ref_id);
        $this->post(self::HOST.'/payments/mellat/callback/'.$attempt->public_id, $this->callbackPayload($attempt))
            ->assertOk()->assertSee('پرداخت تأیید شد')->assertDontSee($order->invoice->invoice_number);
        $this->get(self::HOST.'/orders/'.$order->public_id)->assertOk()->assertSee('خط شما آمادهٔ تنظیم است')->assertSee('خرید تکمیل شد')->assertDontSee('پرداخت با بانک ملت');
        $this->get(self::HOST.'/orders')->assertOk()->assertSee('خرید تکمیل شد')->assertSee('۲۵۰٬۰۰۰');
        $this->assertSame(['bpPayRequest', 'bpVerifyRequest', 'bpSettleRequest'], array_column($fake->calls, 'method'));
        $this->assertSame($buyer->tenant_id, $number->fresh()->tenant_id);
        $this->assertDatabaseCount('number_assignments', 1);
        $this->assertDatabaseCount('number_subscriptions', 1);
    }

    public function test_customer_has_safe_farsi_empty_disabled_and_not_found_states(): void
    {
        [$offer, $buyer, $number, $admin] = $this->screens();
        $this->actingAs($buyer, 'customer')->get(self::HOST.'/orders')->assertOk()->assertSee('هنوز سفارشی ثبت نکرده‌اید');
        app(NumberOfferService::class)->withdraw($admin, $number->id, $number->inventory_revision, 'Synthetic screen withdrawal');
        $this->get(self::HOST.'/numbers')->assertOk()->assertSee('فعلاً شماره‌ای')->assertDontSee('انتخاب و بررسی خرید');
        config(['commerce.catalog_enabled' => false]);
        $this->get(self::HOST.'/numbers')->assertOk()->assertSee('خرید شماره فعلاً در دسترس نیست');
        $this->get(self::HOST.'/orders/'.Str::uuid())->assertNotFound()->assertSee('این سفارش یا شماره پیدا نشد')->assertDontSee('Not Found');
    }

    public function test_unready_stock_is_hidden_and_stale_price_cannot_be_reserved(): void
    {
        [$offer, $buyer, $number, $admin, $fake] = $this->screens();
        $review = $this->actingAs($buyer, 'customer')->get(self::HOST.'/numbers/'.$offer->id.'/checkout')->assertOk();
        $fields = [...$review->viewData('quote'), 'confirmed' => 1, 'idempotency_key' => $review->viewData('key')];
        app(NumberOfferService::class)->withdraw($admin, $number->id, $number->inventory_revision, 'Synthetic repricing');
        app(NumberOfferService::class)->publish($admin, $number->id, $number->fresh()->inventory_revision, $offer->plan_version_id, 300000);
        $this->post(self::HOST.'/orders', $fields)->assertRedirect(self::HOST.'/numbers')->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('commerce_orders', 0);
        $this->get(self::HOST.'/numbers')->assertOk()->assertSee('۳۰۰٬۰۰۰');
        DB::table('sip_numbers')->where('id', $number->id)->update(['enabled' => false]);
        $this->get(self::HOST.'/numbers')->assertOk()->assertDontSee('انتخاب و بررسی خرید');
        $this->assertCount(0, $fake->calls);
    }

    public function test_foreign_customer_and_staff_without_permissions_cannot_buy_or_read_another_tenant_order(): void
    {
        [$offer, $buyer] = $this->screens();
        $order = $this->reserveThroughScreen($offer, $buyer);
        $other = $this->buyer('002');
        $this->actingAs($other, 'customer')->get(self::HOST.'/orders/'.$order->public_id)->assertNotFound()->assertDontSee($order->invoice->invoice_number);
        $this->get(self::HOST.'/orders')->assertOk()->assertDontSee($order->invoice->invoice_number);
        app(CustomerAccountService::class)->setPermissions($buyer, [Permissions::BILLING_VIEW]);
        $this->actingAs($buyer, 'customer')->get(self::HOST.'/orders/'.$order->public_id)->assertOk()->assertDontSee('پرداخت با بانک ملت');
        $this->get(self::HOST.'/numbers')->assertForbidden()->assertSee('دسترسی به این بخش');
        $this->post(self::HOST.'/orders', ['offer_id' => $offer->id])->assertForbidden();
        $this->get('https://admin.blucom.ir/numbers')->assertNotFound();
    }

    public function test_expired_and_uncertain_orders_do_not_offer_another_payment(): void
    {
        [$offer, $buyer, , , $fake] = $this->screens([new PaymentTransportException('Synthetic timeout')]);
        $order = $this->reserveThroughScreen($offer, $buyer);
        $this->post(self::HOST.'/invoices/'.$order->invoice->public_id.'/payments', ['idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->get(self::HOST.'/orders/'.$order->public_id)->assertOk()->assertSee('نتیجهٔ پرداخت در حال بررسی')->assertDontSee('پرداخت با بانک ملت');
        $this->travel(16)->minutes();
        $this->get(self::HOST.'/orders/'.$order->public_id)->assertOk()->assertSee('نتیجهٔ پرداخت در حال بررسی')->assertDontSee('پرداخت با بانک ملت');
        $this->assertCount(1, $fake->calls);
    }

    public function test_expired_unpaid_order_and_disabled_payment_gateway_have_no_pay_button(): void
    {
        [$offer, $buyer, , $admin] = $this->screens();
        $order = $this->reserveThroughScreen($offer, $buyer);
        app(PaymentGatewayService::class)->update($admin, 'mellat', ['revision' => 1, 'enabled' => false, 'amount_unit_confirmed' => true]);
        $this->get(self::HOST.'/orders/'.$order->public_id)->assertOk()->assertDontSee('پرداخت با بانک ملت')->assertSee('پرداخت آنلاین در حال حاضر');
        $this->travel(16)->minutes();
        $this->get(self::HOST.'/orders/'.$order->public_id)->assertOk()->assertSee('مهلت رزرو این شماره پایان یافت')->assertDontSee('پرداخت با بانک ملت');
    }

    public function test_expired_original_hold_returns_to_catalog_without_changing_its_invoice_snapshot(): void
    {
        [$offer, $buyer, $number] = $this->screens();
        $order = $this->reserveThroughScreen($offer, $buyer);
        $this->get(self::HOST.'/numbers')->assertOk()->assertDontSee('انتخاب و بررسی خرید');
        $this->travel(16)->minutes();
        $this->get(self::HOST.'/numbers')->assertOk()->assertSee('انتخاب و بررسی خرید')->assertSee('۲۵۰٬۰۰۰');
        $this->assertSame('expired', $order->reservation->fresh()->status);
        $this->assertNull($number->fresh()->current_reservation_id);
        $this->assertSame(250000, $order->invoice->fresh()->total_amount);
        $this->get(self::HOST.'/orders/'.$order->public_id)->assertOk()->assertSee('مهلت رزرو این شماره پایان یافت');
    }

    public function test_definitive_initiation_failure_can_retry_and_a_ready_attempt_is_continued(): void
    {
        [$offer, $buyer, , , $fake] = $this->screens(['25', '0,SyntheticRetryRef']);
        $order = $this->reserveThroughScreen($offer, $buyer);
        $this->post(self::HOST.'/invoices/'.$order->invoice->public_id.'/payments', ['idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->get(self::HOST.'/orders/'.$order->public_id)->assertOk()->assertSee('شروع پرداخت امکان‌پذیر نشد')->assertSee('پرداخت با بانک ملت');
        $this->post(self::HOST.'/invoices/'.$order->invoice->public_id.'/payments', ['idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $this->get(self::HOST.'/orders/'.$order->public_id)->assertOk()->assertSee('ادامهٔ پرداخت با بانک ملت');
        $this->assertDatabaseCount('commerce_orders', 1);
        $this->assertDatabaseCount('payment_attempts', 2);
        $this->assertCount(2, $fake->calls);
    }

    public function test_guest_redirect_and_csrf_errors_remain_customer_facing(): void
    {
        $this->get(self::HOST.'/numbers')->assertRedirect(self::HOST.'/login');
        $this->get(self::HOST.'/orders')->assertRedirect(self::HOST.'/login');
        [$offer, $buyer] = $this->screens();
        $this->actingAs($buyer, 'customer');
        $this->app['env'] = 'production';
        $this->post(self::HOST.'/orders', ['offer_id' => $offer->id])->assertStatus(419)->assertSee('زمان ورود شما پایان یافته است')->assertDontSee('Page Expired');
    }

    public function test_unconfirmed_checkout_and_forged_bank_return_do_not_show_technical_errors_or_mark_paid(): void
    {
        [$offer, $buyer, , , $fake] = $this->screens();
        $review = $this->actingAs($buyer, 'customer')->get(self::HOST.'/numbers/'.$offer->id.'/checkout');
        $this->post(self::HOST.'/orders', [...$review->viewData('quote'), 'idempotency_key' => $review->viewData('key')])->assertRedirect(self::HOST.'/numbers')->assertSessionHasErrors('checkout');
        $this->assertDatabaseCount('commerce_orders', 0);
        $order = $this->reserveThroughScreen($offer, $buyer);
        $this->post(self::HOST.'/invoices/'.$order->invoice->public_id.'/payments', ['idempotency_key' => (string) Str::uuid()]);
        $attempt = PaymentAttempt::firstOrFail();
        $this->post(self::HOST.'/payments/mellat/callback/'.$attempt->public_id, [...$this->callbackPayload($attempt), 'RefId' => 'forged'])
            ->assertStatus(422)->assertSee('اطلاعات خرید یا پرداخت قابل بررسی نیست')->assertDontSee('RefId');
        $this->assertNull($order->invoice->fresh()->paid_at);
        $this->assertCount(1, $fake->calls);
    }
}
