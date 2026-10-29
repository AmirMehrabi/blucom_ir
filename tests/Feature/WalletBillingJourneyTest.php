<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\CommerceInvoice;
use App\Models\CommerceOrder;
use App\Models\NumberAssignment;
use App\Models\NumberTerm;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Models\SipGateway;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WalletEntry;
use App\Services\Commerce\BillingReminderService;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\LineEntitlementService;
use App\Services\Commerce\NumberInventoryService;
use App\Services\Commerce\NumberOfferService;
use App\Services\Commerce\NumberReservationService;
use App\Services\Commerce\PaymentGatewayService;
use App\Services\Commerce\PlanService;
use App\Services\Commerce\RecurringPaymentService;
use App\Services\Commerce\SubscriptionBillingService;
use App\Services\Commerce\WalletService;
use App\Services\CustomerAccountService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WalletBillingJourneyTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        config(['commerce.catalog_enabled' => true, 'commerce.reservation_enabled' => true,
            'commerce.checkout_enabled' => true, 'commerce.separated_billing_enabled' => true, 'commerce.wallet_enabled' => true]);
        $buyer = app(CustomerAccountService::class)->createOwner('Wallet buyer', '+989120000991', 'Wallet business');
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $gateway = SipGateway::factory()->create(['tenant_id' => null, 'verification_status' => 'approved', 'approved_for_outbound' => true]);
        $number = app(NumberInventoryService::class)->create($admin, [
            'number' => '+982155509876', 'provider_gateway_id' => $gateway->id, 'label' => 'Pilot',
            'enabled' => true, 'inbound_enabled' => true, 'outbound_enabled' => true, 'destination_prefixes' => ['+989'],
        ]);
        app(NumberInventoryService::class)->review($admin, $number->id, 1, 'Synthetic review for wallet test');
        $plan = app(PlanService::class)->create($admin, 'Business');
        $version = app(PlanService::class)->version($admin, $plan->id,
            ['numbers' => 2, 'extensions' => 5, 'queues' => 2, 'ivr_menus' => 2], 100000);
        app(PlanService::class)->publish($admin, $version->id);
        $offer = app(NumberOfferService::class)->publish($admin, $number->id, $number->fresh()->inventory_revision, $version->id, 500000);
        $gateways = app(PaymentGatewayService::class);
        $gateways->update($admin, 'zibal', ['revision' => 0, 'enabled' => true, 'amount_unit_confirmed' => true, 'merchant_id' => 'synthetic_wallet']);
        $gateways->activate($admin, 'zibal');
        Http::preventStrayRequests();
        $checkout = app(CheckoutService::class);
        $quote = $checkout->quote($buyer, $offer->id);
        $order = $checkout->reserve($buyer, $offer->id, $quote, (string) Str::uuid());

        return [$buyer, $order, $number->fresh(), $offer, $admin];
    }

    private function settleTopup(CommerceInvoice $funding, $buyer, string $track = '4836467990'): PaymentAttempt
    {
        Http::swap(new Factory);
        Http::preventStrayRequests();
        Http::fake([
            'gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => (int) $track]),
            'gateway.zibal.ir/v1/verify' => Http::response(['result' => 100, 'amount' => $funding->total_amount * 10, 'refNumber' => $track]),
        ]);
        $payments = app(RecurringPaymentService::class);
        $attempt = $payments->initiate($buyer, $funding->public_id, (string) Str::uuid(), false);

        return $payments->callback($attempt->public_id, ['trackId' => $track, 'success' => '1']);
    }

    private function paidFirst(): array
    {
        $fixture = $this->fixture();
        [$buyer, $order] = $fixture;
        $funding = app(WalletService::class)->prepare($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->settleTopup($funding, $buyer);

        return $fixture;
    }

    public function test_first_purchase_credits_then_spends_once_and_grants_a_permanent_line(): void
    {
        [$buyer, $order, $number] = $this->fixture();
        $wallet = app(WalletService::class);
        $funding = $wallet->prepare($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->assertSame(600000, $funding->total_amount);
        $attempt = $this->settleTopup($funding, $buyer);
        app(RecurringPaymentService::class)->callback($attempt->public_id, ['trackId' => $attempt->ref_id, 'success' => '1']);
        $this->assertSame(0, $wallet->balance($buyer->tenant_id));
        $this->assertDatabaseCount('wallet_entries', 2);
        $this->assertDatabaseCount('number_assignments', 1);
        $this->assertSame('paid', $order->invoice->fresh()->status);
        $this->assertNull($order->invoice->fresh()->paid_payment_attempt_id);
        $this->assertTrue($order->invoice->fresh()->hasValidSettlement());
        $this->assertSame('one_time_number', NumberTerm::firstOrFail()->billing_model);
        $this->assertNull(NumberTerm::firstOrFail()->period_ends_at);
        $this->assertTrue(TenantSubscription::firstOrFail()->wallet_auto_renew);
        $this->assertTrue(app(LineEntitlementService::class)->allows($number->fresh()));
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/orders/'.$order->public_id)
            ->assertOk()->assertSee('تنظیم خط و اتصال تلفن');
    }

    public function test_review_confirmation_goes_straight_to_shortfall_payment_and_returns_to_setup(): void
    {
        [$buyer, $oldOrder, , $offer] = $this->fixture();
        app(NumberReservationService::class)->cancel($buyer, $oldOrder->public_id);
        $quote = app(CheckoutService::class)->quote($buyer, $offer->id);
        $response = $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/numbers/'.$offer->id.'/checkout')
            ->assertOk()->assertSee('خرید یک‌باره شماره')->assertSee('ماه اول پلن')->assertSee('پرداخت ماهانه بعدی');
        if ($directory = getenv('BILLING_QA_DIR')) {
            file_put_contents($directory.'/review.html', $response->getContent());
        }
        Http::fake(['gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 4836467990]),
            'gateway.zibal.ir/v1/verify' => Http::response(['result' => 100, 'amount' => 6000000, 'refNumber' => '4836467990'])]);
        $this->post('https://my.blucom.ir/orders', [...$quote, 'confirmed' => '1', 'idempotency_key' => (string) Str::uuid()])->assertRedirect();
        $attempt = PaymentAttempt::firstOrFail();
        $this->assertSame(600000, $attempt->business_amount);
        $this->get('https://my.blucom.ir/payments/zibal/callback/'.$attempt->public_id.'?trackId=4836467990&success=1')->assertOk();
        $this->get('https://my.blucom.ir/payments/'.$attempt->public_id.'/return')->assertRedirect();
        $order = CommerceOrder::latest('id')->firstOrFail();
        $response = $this->get('https://my.blucom.ir/orders/'.$order->public_id)->assertOk()->assertSee('تنظیم خط و اتصال تلفن');
        if ($directory = getenv('BILLING_QA_DIR')) {
            file_put_contents($directory.'/purchase-success.html', $response->getContent());
        }
    }

    public function test_wallet_transactions_and_subscription_screens_remain_usable_after_purchase(): void
    {
        [$buyer] = $this->paidFirst();
        $response = $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/billing?tab=transactions')
            ->assertOk()->assertSee('شارژ کیف پول')->assertSee('خرید شماره و پلن')->assertSee('برداشت');
        if ($directory = getenv('BILLING_QA_DIR')) {
            file_put_contents($directory.'/settled-transactions.html', $response->getContent());
        }
        $this->get('https://my.blucom.ir/billing?tab=subscription')->assertOk()->assertSee('خرید یک‌باره، بدون تمدید');
    }

    public function test_shortfall_funding_reuses_the_purchase_and_never_collects_the_full_price_again(): void
    {
        [$buyer, $order] = $this->fixture();
        $wallet = app(WalletService::class);
        $this->settleTopup($wallet->topup($buyer, 200000, (string) Str::uuid()), $buyer);
        $funding = $wallet->prepare($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->assertSame(400000, $funding->total_amount);
        $this->assertSame($funding->id, $wallet->prepare($buyer, $order->invoice->public_id, (string) Str::uuid())->id);
        $this->settleTopup($funding, $buyer, '4836467991');
        $this->assertSame(0, $wallet->balance($buyer->tenant_id));
        $this->assertSame('allocated', $order->fresh()->status);
    }

    public function test_expired_reservation_keeps_all_bank_funds_in_wallet_and_offers_another_line(): void
    {
        [$buyer, $order] = $this->fixture();
        $wallet = app(WalletService::class);
        $funding = $wallet->prepare($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->travel(16)->minutes();
        app(NumberReservationService::class)->expire($order->reservation->id);
        $this->settleTopup($funding, $buyer);
        $this->assertSame(600000, $wallet->balance($buyer->tenant_id));
        $this->assertDatabaseCount('number_assignments', 0);
        $this->assertDatabaseCount('wallet_entries', 1);
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/orders/'.$order->public_id)
            ->assertOk()->assertSee('انتخاب شماره دیگر');
    }

    public function test_monthly_recovery_starts_a_fresh_month_after_grace_and_does_not_recharge_the_line(): void
    {
        [$buyer, $order, $number] = $this->paidFirst();
        $subscription = TenantSubscription::firstOrFail();
        $this->travelTo($subscription->period_ends_at->subDays(2));
        app(SubscriptionBillingService::class)->issuePlan($subscription->id);
        $this->travel(2)->years();
        $this->assertFalse(app(LineEntitlementService::class)->allows($number->fresh()));
        $invoice = app(SubscriptionBillingService::class)->issuePlan($subscription->id);
        $this->assertSame(100000, $invoice->total_amount);
        $response = $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/billing/invoices/'.$invoice->public_id)
            ->assertOk()->assertSee('یک ماه تازه')->assertSee('شارژ');
        $overview = $this->get('https://my.blucom.ir/billing')->assertOk()->assertSee('بازفعال‌سازی اشتراک و تماس‌ها');
        if ($directory = getenv('BILLING_QA_DIR')) {
            file_put_contents($directory.'/renewal.html', $response->getContent());
            file_put_contents($directory.'/suspended.html', $overview->getContent());
        }
        $funding = app(WalletService::class)->prepare($buyer, $invoice->public_id, (string) Str::uuid());
        $this->settleTopup($funding, $buyer, '4836467991');
        $this->assertTrue($subscription->fresh()->period_starts_at->equalTo(now()->startOfSecond()));
        $this->assertTrue($subscription->fresh()->period_ends_at->gt(now()->addDays(27)));
        $this->assertTrue(app(LineEntitlementService::class)->allows($number->fresh()));
        $this->assertNull(app(SubscriptionBillingService::class)->issueNumber(NumberTerm::firstOrFail()->id));
        $this->assertSame(1, NumberAssignment::count());
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/billing/invoices/'.$invoice->public_id)
            ->assertOk()->assertSee('تمدید اعمال شد');
    }

    public function test_due_renewal_uses_existing_wallet_funds_once_without_a_bank_call(): void
    {
        [$buyer] = $this->paidFirst();
        $wallet = app(WalletService::class);
        $this->settleTopup($wallet->topup($buyer, 100000, (string) Str::uuid()), $buyer, '4836467991');
        $this->travelTo(TenantSubscription::firstOrFail()->period_ends_at);
        Http::fake();
        $this->assertSame(1, $wallet->recoverDue());
        $this->assertSame(0, $wallet->recoverDue());
        $this->assertSame(0, $wallet->balance($buyer->tenant_id));
        Http::assertNothingSent();
    }

    public function test_insufficient_balance_does_not_partially_debit_and_recovers_after_standalone_topup(): void
    {
        [$buyer] = $this->paidFirst();
        $wallet = app(WalletService::class);
        $this->travelTo(TenantSubscription::firstOrFail()->period_ends_at->addDay());
        $this->assertSame(0, $wallet->recoverDue());
        $this->assertSame(0, $wallet->balance($buyer->tenant_id));
        $this->settleTopup($wallet->topup($buyer, 100000, (string) Str::uuid()), $buyer, '4836467991');
        $this->assertSame(0, $wallet->recoverDue());
        $this->assertSame('paid', CommerceInvoice::where('kind', 'plan_renewal')->firstOrFail()->status);
    }

    public function test_auto_renew_off_keeps_topups_unspent(): void
    {
        [$buyer] = $this->paidFirst();
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/billing/auto-renew', ['enabled' => '0'])->assertRedirect();
        $wallet = app(WalletService::class);
        $this->travelTo(TenantSubscription::firstOrFail()->period_ends_at->addDay());
        $this->settleTopup($wallet->topup($buyer, 100000, (string) Str::uuid()), $buyer, '4836467991');
        $this->assertSame(0, $wallet->recoverDue());
        $this->assertSame(100000, $wallet->balance($buyer->tenant_id));
    }

    public function test_checkout_and_recovery_screens_show_precise_prices_and_safe_pending_states(): void
    {
        [$buyer, $order, , $offer] = $this->fixture();
        // A held offer is intentionally unavailable to a second quote. The retained order is the recovery screen.
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/orders/'.$order->public_id)->assertOk()->assertSee('پرداخت سفارش');
        $funding = app(WalletService::class)->prepare($buyer, $order->invoice->public_id, (string) Str::uuid());
        Http::fake(['gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 4836467990])]);
        $attempt = app(RecurringPaymentService::class)->initiate($buyer, $funding->public_id, (string) Str::uuid(), false);
        $attempt->update(['status' => 'unknown']);
        $this->get('https://my.blucom.ir/orders/'.$order->public_id)->assertOk()->assertSee('پرداخت دوباره لازم نیست')->assertDontSee('شارژ ۶۰۰', false);
        foreach (['overview', 'subscription', 'transactions', 'invoices'] as $tab) {
            $response = $this->get('https://my.blucom.ir/billing?tab='.$tab)->assertOk();
            if ($directory = getenv('BILLING_QA_DIR')) {
                file_put_contents($directory.'/'.$tab.'.html', $response->getContent());
            }
        }
    }

    public function test_wallet_and_saved_purchase_intents_are_tenant_scoped(): void
    {
        [$buyer, $order] = $this->fixture();
        $other = app(CustomerAccountService::class)->createOwner('Other wallet', '+989120000992', 'Private wallet');
        $this->actingAs($other, 'customer')->post('https://my.blucom.ir/billing/invoices/'.$order->invoice->public_id.'/wallet', ['idempotency_key' => (string) Str::uuid()])->assertNotFound();
        $this->assertDatabaseCount('wallet_entries', 0);
        $this->assertDatabaseCount('commerce_invoices', 1);
    }

    public function test_test_mode_cannot_create_spendable_wallet_credit(): void
    {
        [$buyer, $order, , , $admin] = $this->fixture();
        $settings = app(PaymentGatewayService::class);
        $settings->update($admin, 'zibal', ['revision' => 1, 'enabled' => true, 'amount_unit_confirmed' => true, 'merchant_id' => 'zibal', 'mode' => 'test']);
        $this->expectException(ValidationException::class);
        app(WalletService::class)->prepare($buyer, $order->invoice->public_id, (string) Str::uuid());
    }

    public function test_existing_funds_purchase_works_when_the_gateway_is_unavailable(): void
    {
        [$buyer, $order] = $this->fixture();
        $wallet = app(WalletService::class);
        $this->settleTopup($wallet->topup($buyer, 600000, (string) Str::uuid()), $buyer);
        config(['commerce.payment_provider' => 'missing']);
        PaymentGateway::query()->update(['enabled' => false]);
        Http::swap(new Factory);
        Http::preventStrayRequests();
        $this->assertNull($wallet->prepare($buyer, $order->invoice->public_id, (string) Str::uuid()));
        $this->assertNull($wallet->prepare($buyer, $order->invoice->public_id, (string) Str::uuid()));
        $this->assertSame(0, $wallet->balance($buyer->tenant_id));
        $this->assertDatabaseCount('wallet_entries', 2);
        Http::assertNothingSent();
    }

    public function test_unverified_topup_never_adds_balance(): void
    {
        [$buyer, $order] = $this->fixture();
        $funding = app(WalletService::class)->prepare($buyer, $order->invoice->public_id, (string) Str::uuid());
        try {
            app(WalletService::class)->applyTopup($funding->id);
            $this->fail('An unverified credit must fail.');
        } catch (ValidationException) {
            $this->assertSame(0, app(WalletService::class)->balance($buyer->tenant_id));
        }
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_cancelling_subscription_does_not_release_the_one_time_line_or_spend_wallet(): void
    {
        [$buyer, , $number] = $this->paidFirst();
        app(SubscriptionBillingService::class)->cancelPlan($buyer);
        $wallet = app(WalletService::class);
        $this->settleTopup($wallet->topup($buyer, 100000, (string) Str::uuid()), $buyer, '4836467991');
        $this->travelTo(TenantSubscription::firstOrFail()->cancel_at->addDay());
        $this->assertSame(0, $wallet->recoverDue());
        $this->assertSame($buyer->tenant_id, $number->fresh()->tenant_id);
        $this->assertSame(100000, $wallet->balance($buyer->tenant_id));
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/billing/renew')->assertRedirect();
        $this->assertFalse(app(LineEntitlementService::class)->allows($number->fresh()));
        $invoice = CommerceInvoice::where('kind', 'plan_renewal')->firstOrFail();
        $this->assertNull($wallet->prepare($buyer, $invoice->public_id, (string) Str::uuid()));
        $this->assertTrue(app(LineEntitlementService::class)->allows($number->fresh()));
    }

    public function test_admin_can_inspect_wallet_and_one_time_inventory_without_exposing_merchant_credentials(): void
    {
        [$buyer, , , , $admin] = $this->paidFirst();
        $this->actingAs($admin, 'web')->get('https://admin.blucom.ir/admin/customers/'.$buyer->id.'?tab=billing')
            ->assertOk()->assertSee('کیف پول کسب‌وکار')->assertSee('پرداخت‌شده از کیف پول')->assertDontSee('synthetic_wallet');
        $this->get('https://admin.blucom.ir/admin/inventory')->assertOk()->assertSee('خرید یک‌باره');
    }

    public function test_view_only_billing_member_cannot_spend_or_enable_auto_renewal(): void
    {
        [$buyer, $order] = $this->fixture();
        $viewer = app(CustomerAccountService::class)->createStaff($buyer->tenant, 'Viewer', '+989120000993', [Permissions::BILLING_VIEW]);
        $this->actingAs($viewer, 'customer')->post('https://my.blucom.ir/billing/invoices/'.$order->invoice->public_id.'/wallet', ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->post('https://my.blucom.ir/billing/auto-renew', ['enabled' => 1])->assertForbidden();
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_expired_undelivered_funding_can_be_replaced_but_unknown_bank_payment_cannot(): void
    {
        [$buyer, $order] = $this->paidFirst();
        $this->travelTo(TenantSubscription::firstOrFail()->period_ends_at);
        $invoice = app(SubscriptionBillingService::class)->issuePlan(TenantSubscription::firstOrFail()->id);
        $wallet = app(WalletService::class);
        $old = $wallet->prepare($buyer, $invoice->public_id, (string) Str::uuid());
        $this->travel(2)->days();
        $new = $wallet->prepare($buyer, $invoice->public_id, (string) Str::uuid());
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('void', $old->fresh()->status);
        Http::swap(new Factory);
        Http::fake(['gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 4836467991])]);
        $attempt = app(RecurringPaymentService::class)->initiate($buyer, $new->public_id, (string) Str::uuid(), false);
        $attempt->update(['status' => 'unknown']);
        $this->travel(2)->days();
        $this->assertSame($new->id, $wallet->prepare($buyer, $invoice->public_id, (string) Str::uuid())->id);
        $this->assertTrue($wallet->summary($invoice, $buyer)['pending']);
    }

    public function test_paid_topup_awaiting_credit_does_not_offer_another_bank_payment(): void
    {
        [$buyer, $order] = $this->fixture();
        $wallet = app(WalletService::class);
        $funding = $wallet->prepare($buyer, $order->invoice->public_id, (string) Str::uuid());
        $funding->update(['status' => 'paid', 'paid_at' => now()]);
        $this->assertTrue($wallet->summary($order->invoice, $buyer)['pending']);
        $this->assertSame($funding->id, $wallet->prepare($buyer, $order->invoice->public_id, (string) Str::uuid())->id);
        $this->assertDatabaseCount('wallet_entries', 0);
    }

    public function test_funded_automatic_renewal_does_not_send_an_unnecessary_payment_reminder(): void
    {
        [$buyer] = $this->paidFirst();
        $wallet = app(WalletService::class);
        $this->settleTopup($wallet->topup($buyer, 100000, (string) Str::uuid()), $buyer, '4836467991');
        $this->travelTo(TenantSubscription::firstOrFail()->period_ends_at->subDays(3));
        app(SubscriptionBillingService::class)->issuePlan(TenantSubscription::firstOrFail()->id);
        app(BillingReminderService::class)->sendDue();
        $this->assertSame(0, DB::table('notifications')->where('type', 'billing_reminder')->count());
    }

    public function test_low_balance_reminder_names_the_shortfall_and_links_to_the_renewal(): void
    {
        [$buyer] = $this->paidFirst();
        $wallet = app(WalletService::class);
        $this->settleTopup($wallet->topup($buyer, 40000, (string) Str::uuid()), $buyer, '4836467991');
        $this->travelTo(TenantSubscription::firstOrFail()->period_ends_at->subDays(3));
        $invoice = app(SubscriptionBillingService::class)->issuePlan(TenantSubscription::firstOrFail()->id);
        app(BillingReminderService::class)->sendDue();
        $notification = json_decode(DB::table('notifications')->where('type', 'billing_reminder')->first()->data, true);
        $this->assertStringContainsString('۶۰٬۰۰۰', $notification['message']);
        $this->assertStringContainsString($invoice->public_id, $notification['url']);
    }

    public function test_ledger_entries_cannot_be_changed_or_deleted(): void
    {
        [$buyer] = $this->paidFirst();
        $this->expectException(ValidationException::class);
        WalletEntry::firstOrFail()->update(['amount' => 999999]);
    }
}
