<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\CommerceInvoice;
use App\Models\Customer;
use App\Models\NumberTerm;
use App\Models\PaymentAttempt;
use App\Models\SipGateway;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\LineEntitlementService;
use App\Services\Commerce\NumberInventoryService;
use App\Services\Commerce\NumberOfferService;
use App\Services\Commerce\PaidOrderAllocationService;
use App\Services\Commerce\PlanService;
use App\Services\Commerce\PaymentGatewayService;
use App\Services\Commerce\SubscriptionBillingService;
use App\Services\CustomerAccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeparatedBillingTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['commerce.catalog_enabled' => true, 'commerce.reservation_enabled' => true,
            'commerce.checkout_enabled' => true, 'commerce.separated_billing_enabled' => true]);
        $buyer = app(CustomerAccountService::class)->createOwner('Owner', '+989120000991', 'Business');
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $gateway = SipGateway::factory()->create(['tenant_id' => null, 'verification_status' => 'approved', 'approved_for_outbound' => true]);
        $number = app(NumberInventoryService::class)->create($admin, [
            'number' => '+982155509876', 'provider_gateway_id' => $gateway->id, 'label' => 'Pilot',
            'enabled' => true, 'inbound_enabled' => true, 'outbound_enabled' => true, 'destination_prefixes' => ['+989'],
        ]);
        app(NumberInventoryService::class)->review($admin, $number->id, 1, 'Synthetic review for billing test');
        $plan = app(PlanService::class)->create($admin, 'Business');
        $version = app(PlanService::class)->version($admin, $plan->id,
            ['numbers' => 2, 'extensions' => 5, 'queues' => 2, 'ivr_menus' => 2], 100000);
        app(PlanService::class)->publish($admin, $version->id);
        $offer = app(NumberOfferService::class)->publish($admin, $number->id, $number->fresh()->inventory_revision, $version->id, 500000);

        return [$buyer, $number->fresh(), $offer, $version];
    }

    private function paidFirst(): array
    {
        [$buyer, $number, $offer] = $this->fixture();
        $checkout = app(CheckoutService::class);
        $quote = $checkout->quote($buyer, $offer->id);
        $order = $checkout->reserve($buyer, $offer->id, $quote, (string) Str::uuid());
        $invoice = $order->invoice;
        $attempt = PaymentAttempt::query()->create([
            'public_id' => (string) Str::uuid(), 'commerce_invoice_id' => $invoice->id,
            'idempotency_key' => (string) Str::uuid(), 'provider' => 'mellat', 'account_key' => 'synthetic',
            'business_amount' => 600000, 'business_currency' => 'IRT', 'gateway_amount' => 6000000,
            'gateway_unit' => 'IRR', 'status' => 'settled', 'verified_at' => now(), 'settled_at' => now(),
        ]);
        $invoice->update(['status' => 'paid', 'paid_payment_attempt_id' => $attempt->id, 'paid_at' => now()]);
        $order->update(['status' => 'paid_pending_allocation']);
        app(PaidOrderAllocationService::class)->allocate($order->id);

        return [$buyer, $number->fresh(), $order->fresh()];
    }

    public function test_first_checkout_charges_month_and_year_once_and_grants_separate_terms(): void
    {
        [$buyer, $number, $order] = $this->paidFirst();
        $this->assertSame(600000, $order->invoice->total_amount);
        $this->assertEqualsCanonicalizing(['monthly_plan', 'annual_number'], $order->invoice->items()->pluck('kind')->all());
        $this->assertSame(100000, TenantSubscription::query()->where('tenant_id', $buyer->tenant_id)->firstOrFail()->monthly_amount);
        $this->assertSame(500000, NumberTerm::firstOrFail()->annual_amount);
        $this->assertTrue(app(LineEntitlementService::class)->allows($number));
        $this->assertSame(2, app(LineEntitlementService::class)->limits($buyer->tenant)['numbers']);
    }

    public function test_first_buyer_can_select_a_different_published_monthly_plan_for_the_same_number(): void
    {
        [$buyer, , $offer] = $this->fixture();
        $admin = User::query()->where('user_type', UserType::Admin)->firstOrFail();
        $plan = app(PlanService::class)->create($admin, 'Growth');
        $version = app(PlanService::class)->version($admin, $plan->id,
            ['numbers' => 4, 'extensions' => 10, 'queues' => 3, 'ivr_menus' => 4], 200000);
        app(PlanService::class)->publish($admin, $version->id);
        $checkout = app(CheckoutService::class);
        $quote = $checkout->quote($buyer, $offer->id, $version->id);
        $this->assertSame(700000, $quote['monthly_amount']);
        $this->assertSame($version->id, $quote['plan_version_id']);
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/numbers/'.$offer->id.'/checkout?plan='.$version->id)
            ->assertOk()->assertSee('Growth');
        $order = $checkout->reserve($buyer, $offer->id, $quote, (string) Str::uuid());
        $this->assertSame(700000, $order->invoice->total_amount);
    }

    public function test_new_customer_needs_subscription_before_configuring_service(): void
    {
        [$buyer] = $this->fixture();
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/teams', ['name' => 'Before purchase'])
            ->assertRedirect('https://my.blucom.ir/numbers');
    }

    public function test_monthly_and_annual_renewals_have_distinct_due_dates_and_suspend_independently(): void
    {
        [$buyer, $number] = $this->paidFirst();
        $billing = app(SubscriptionBillingService::class);
        $this->travel(24)->days();
        $monthly = $billing->issuePlan(TenantSubscription::firstOrFail()->id);
        $this->assertNotNull($monthly);
        $this->assertSame('plan_renewal', $monthly->kind);
        $this->assertSame(100000, $monthly->total_amount);
        $this->assertNull($billing->issueNumber(NumberTerm::firstOrFail()->id));
        $this->travel(14)->days();
        $this->assertFalse(app(LineEntitlementService::class)->allows($number));
        $this->assertTrue(NumberTerm::firstOrFail()->grace_ends_at->isFuture());
    }

    public function test_price_increase_waits_for_notice_and_a_later_renewal(): void
    {
        [$buyer] = $this->paidFirst();
        $current = TenantSubscription::firstOrFail();
        $plan = $current->planVersion->plan;
        $admin = User::query()->where('user_type', UserType::Admin)->firstOrFail();
        $new = app(PlanService::class)->version($admin, $plan->id,
            ['numbers' => 2, 'extensions' => 5, 'queues' => 2, 'ivr_menus' => 2], 120000);
        app(PlanService::class)->publish($admin, $new->id);
        $this->assertSame('price_notice', $current->fresh()->pending_change_source);

        $this->travel(24)->days();
        $billing = app(SubscriptionBillingService::class);
        $oldInvoice = $billing->issuePlan($current->id);
        $this->assertSame(100000, $oldInvoice->total_amount);
        config(['services.kavenegar.api_key' => 'test-key', 'services.kavenegar.sms_sender' => 'test-sender']);
        Http::fake(['api.kavenegar.com/*' => Http::response(['return' => ['status' => 200]], 200)]);
        $this->assertSame(1, app(\App\Services\Commerce\BillingReminderService::class)->sendPriceNotices());
        $this->assertNotNull($current->fresh()->price_notice_sent_at);

        $this->travel(35)->days();
        $attempt = PaymentAttempt::query()->create([
            'public_id' => (string) Str::uuid(), 'commerce_invoice_id' => $oldInvoice->id,
            'idempotency_key' => (string) Str::uuid(), 'provider' => 'mellat', 'account_key' => 'synthetic',
            'business_amount' => 100000, 'business_currency' => 'IRT', 'gateway_amount' => 1000000,
            'gateway_unit' => 'IRR', 'status' => 'settled', 'verified_at' => now(), 'settled_at' => now(),
        ]);
        $oldInvoice->update(['status' => 'paid', 'paid_payment_attempt_id' => $attempt->id, 'paid_at' => now()]);
        $billing->applyPaid($oldInvoice->id);
        $this->assertSame(100000, $current->fresh()->monthly_amount);
        $this->assertSame($new->id, $current->fresh()->pending_plan_version_id);
        $this->travel(24)->days();
        $newInvoice = $billing->issuePlan($current->id);
        $this->assertSame(120000, $newInvoice->total_amount);
        $this->assertSame($new->id, $newInvoice->plan_version_id);
    }

    public function test_annual_number_price_increase_requires_delivered_notice(): void
    {
        [, $number] = $this->paidFirst();
        $term = NumberTerm::firstOrFail();
        $admin = User::query()->where('user_type', UserType::Admin)->firstOrFail();
        app(SubscriptionBillingService::class)->scheduleNumberPrice($admin, $number->id, 600000);
        $this->assertSame(600000, $term->fresh()->pending_annual_amount);
        config(['services.kavenegar.api_key' => 'test-key', 'services.kavenegar.sms_sender' => 'test-sender']);
        Http::fake(['api.kavenegar.com/*' => Http::response(['return' => ['status' => 200]], 200)]);
        $this->assertSame(1, app(\App\Services\Commerce\BillingReminderService::class)->sendPriceNotices());
        $this->travel(336)->days();
        $invoice = app(SubscriptionBillingService::class)->issueNumber($term->id);
        $this->assertSame(600000, $invoice->total_amount);
        $this->assertSame('number_renewal', $invoice->kind);
    }

    public function test_upgrade_is_prorated_and_only_changes_capacity_after_settlement(): void
    {
        [$buyer] = $this->paidFirst();
        $admin = User::query()->where('user_type', UserType::Admin)->firstOrFail();
        $plan = app(PlanService::class)->create($admin, 'Larger');
        $version = app(PlanService::class)->version($admin, $plan->id,
            ['numbers' => 4, 'extensions' => 10, 'queues' => 3, 'ivr_menus' => 4], 200000);
        app(PlanService::class)->publish($admin, $version->id);
        $subscription = TenantSubscription::firstOrFail();
        $this->travel(15)->days();
        $invoice = app(SubscriptionBillingService::class)->requestChange($buyer, $version->id);
        $this->assertSame('plan_upgrade', $invoice->kind);
        $this->assertGreaterThan(0, $invoice->total_amount);
        $this->assertLessThan(100000, $invoice->total_amount);
        $this->assertSame(2, app(LineEntitlementService::class)->limits($buyer->tenant)['numbers']);
        $attempt = PaymentAttempt::query()->create([
            'public_id' => (string) Str::uuid(), 'commerce_invoice_id' => $invoice->id,
            'idempotency_key' => (string) Str::uuid(), 'provider' => 'mellat', 'account_key' => 'synthetic',
            'business_amount' => $invoice->total_amount, 'business_currency' => 'IRT',
            'gateway_amount' => $invoice->total_amount * 10, 'gateway_unit' => 'IRR',
            'status' => 'settled', 'verified_at' => now(), 'settled_at' => now(),
        ]);
        $invoice->update(['status' => 'paid', 'paid_payment_attempt_id' => $attempt->id, 'paid_at' => now()]);
        app(SubscriptionBillingService::class)->applyPaid($invoice->id);
        $this->assertSame(4, app(LineEntitlementService::class)->limits($buyer->tenant)['numbers']);
        $this->assertSame($version->id, $subscription->fresh()->plan_version_id);
    }

    public function test_customer_can_view_own_paid_receipt_but_not_another_tenants(): void
    {
        [$buyer, , $order] = $this->paidFirst();
        $url = 'https://my.blucom.ir/billing/invoices/'.$order->invoice->public_id;
        $this->actingAs($buyer, 'customer')->get($url)->assertOk()->assertSee($order->invoice->invoice_number);
        $other = app(CustomerAccountService::class)->createOwner('Other', '+989120000992', 'Other business');
        $this->actingAs($other, 'customer')->get($url)->assertNotFound();
    }

    public function test_zibal_monthly_renewal_settles_once_and_extends_the_plan(): void
    {
        [$buyer] = $this->paidFirst();
        $admin = User::query()->where('user_type', UserType::Admin)->firstOrFail();
        $gateways = app(PaymentGatewayService::class);
        $gateways->update($admin, 'zibal', ['revision' => 0, 'enabled' => true,
            'amount_unit_confirmed' => true, 'merchant_id' => 'synthetic_zibal']);
        $gateways->activate($admin, 'zibal');
        Http::preventStrayRequests();
        Http::fake([
            'gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 4836467990]),
            'gateway.zibal.ir/v1/verify' => Http::response(['result' => 100, 'amount' => 1000000, 'refNumber' => '7654321']),
        ]);
        $subscription = TenantSubscription::firstOrFail();
        $oldEnd = $subscription->period_ends_at;
        $this->travel(24)->days();
        $invoice = app(SubscriptionBillingService::class)->issuePlan($subscription->id);
        $key = (string) Str::uuid();
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/invoices/'.$invoice->public_id.'/payments',
            ['idempotency_key' => $key, 'payment_mode' => 'live'])->assertRedirect();
        $attempt = PaymentAttempt::query()->where('commerce_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame('redirect_ready', $attempt->status);
        $callback = 'https://my.blucom.ir/payments/zibal/callback/'.$attempt->public_id.'?trackId=4836467990&success=1';
        $this->get($callback)->assertOk();
        $this->get($callback)->assertOk();
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->applied_at);
        $this->assertTrue($subscription->fresh()->period_ends_at->gt($oldEnd));
        $this->assertSame(1, PaymentAttempt::query()->where('commerce_invoice_id', $invoice->id)->count());
        $this->actingAs($admin, 'web')->get('https://admin.blucom.ir/admin/payments')
            ->assertOk()->assertSee($invoice->invoice_number);
        $this->post('https://admin.blucom.ir/admin/payments/'.$attempt->id.'/reconcile',
            ['reason' => 'Synthetic duplicate reconciliation check'])->assertRedirect();
        Http::assertSentCount(2);
    }

    public function test_unready_paid_number_offers_refund_or_replacement_review_after_24_hours(): void
    {
        [$buyer, , $offer] = $this->fixture();
        $checkout = app(CheckoutService::class);
        $quote = $checkout->quote($buyer, $offer->id);
        $order = $checkout->reserve($buyer, $offer->id, $quote, (string) Str::uuid());
        $invoice = $order->invoice;
        $attempt = PaymentAttempt::query()->create([
            'public_id' => (string) Str::uuid(), 'commerce_invoice_id' => $invoice->id,
            'idempotency_key' => (string) Str::uuid(), 'provider' => 'mellat', 'account_key' => 'synthetic',
            'business_amount' => $invoice->total_amount, 'business_currency' => 'IRT',
            'gateway_amount' => $invoice->total_amount * 10, 'gateway_unit' => 'IRR',
            'status' => 'settled', 'verified_at' => now(), 'settled_at' => now(),
        ]);
        $invoice->update(['status' => 'paid', 'paid_payment_attempt_id' => $attempt->id, 'paid_at' => now()]);
        $order->update(['status' => 'paid_unfulfilled']);
        $url = 'https://my.blucom.ir/orders/'.$order->public_id.'/unready-request';
        $this->actingAs($buyer, 'customer')->post($url, ['choice' => 'refund'])->assertSessionHasErrors('fulfillment');
        $this->travel(25)->hours();
        $other = app(CustomerAccountService::class)->createOwner('Other', '+989120000993', 'Other business');
        $this->actingAs($other, 'customer')->post($url, ['choice' => 'refund'])->assertNotFound();
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/orders/'.$order->public_id)
            ->assertOk()->assertSee('درخواست شماره جایگزین')->assertSee('درخواست بازپرداخت');
        $this->post($url, ['choice' => 'refund'])->assertRedirect();
        $this->post($url, ['choice' => 'refund'])->assertRedirect();
        $this->assertDatabaseCount('billing_fulfillment_requests', 1);
    }
}
