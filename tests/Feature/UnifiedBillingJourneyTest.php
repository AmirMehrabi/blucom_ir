<?php

namespace Tests\Feature;

use App\Models\CommerceInvoice;
use App\Models\PaymentAttempt;
use App\Models\TenantSubscription;
use App\Services\CustomerAccountService;
use App\Services\Portal\PortalLabels;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Support\MellatFixtures;
use Tests\TestCase;

class UnifiedBillingJourneyTest extends TestCase
{
    use MellatFixtures, RefreshDatabase;

    public function test_all_billing_tabs_and_receipts_remain_scoped_to_customer(): void
    {
        [$order, $buyer] = $this->paymentFixture();
        $other = app(CustomerAccountService::class)->createOwner('Other buyer', '+989120001231', 'Private other business');
        $this->actingAs($buyer, 'customer');
        foreach (['overview', 'invoices', 'orders', 'subscription'] as $tab) {
            $this->get('https://my.blucom.ir/billing?tab='.$tab)->assertOk()->assertSee('صورتحساب و خریدها')->assertDontSee('Private other business');
        }
        $this->get('https://my.blucom.ir/billing')->assertSee('قدم بعدی در پرداخت‌ها')->assertSee($order->invoice->invoice_number);
        $this->actingAs($other, 'customer')->get('https://my.blucom.ir/billing/invoices/'.$order->invoice->public_id)->assertNotFound();
    }

    public function test_pending_renewal_payment_never_offers_a_second_payment(): void
    {
        [$order, $buyer] = $this->paymentFixture();
        $invoice = CommerceInvoice::query()->create(['public_id' => (string) Str::uuid(), 'invoice_number' => 'RENEWAL-TEST', 'customer_id' => $buyer->id, 'tenant_id' => $buyer->tenant_id,
            'commerce_order_id' => null, 'kind' => 'plan_renewal', 'status' => 'issued', 'total_amount' => 123000, 'currency' => 'IRT', 'buyer_snapshot' => ['customer_name' => 'Buyer', 'business_name' => 'Business'], 'issued_at' => now(), 'expires_at' => now()->addDay(), 'due_at' => now()->subDay()]);
        $attempt = PaymentAttempt::query()->create(['public_id' => (string) Str::uuid(), 'commerce_invoice_id' => $invoice->id, 'provider' => 'mellat', 'status' => 'unknown', 'business_amount' => 123000, 'business_currency' => 'IRT', 'idempotency_key' => (string) Str::uuid(), 'account_key' => 'synthetic', 'gateway_amount' => 1230000, 'gateway_unit' => 'IRR']);
        $invoice->update(['current_payment_attempt_id' => $attempt->id]);
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/billing/invoices/'.$invoice->public_id)->assertOk()->assertSee('پرداخت دوباره لازم نیست')->assertDontSee('name="idempotency_key"', false);
        $this->get('https://my.blucom.ir/billing')->assertSee('RENEWAL-TEST')->assertSee('پیگیری پرداخت در حال بررسی');
    }

    public function test_unavailable_payment_gateway_has_a_recovery_message_instead_of_a_dead_end(): void
    {
        [$order, $buyer] = $this->paymentFixture();
        $invoice = CommerceInvoice::query()->create(['public_id' => (string) Str::uuid(), 'invoice_number' => 'UNAVAILABLE-TEST', 'customer_id' => $buyer->id, 'tenant_id' => $buyer->tenant_id,
            'commerce_order_id' => null, 'kind' => 'plan_renewal', 'status' => 'issued', 'total_amount' => 123000, 'currency' => 'IRT', 'buyer_snapshot' => [], 'issued_at' => now(), 'expires_at' => now()->addDay()]);
        config(['commerce.checkout_enabled' => false]);
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/billing/invoices/'.$invoice->public_id)->assertOk()->assertSee('پرداخت آنلاین فعلاً در دسترس نیست')->assertDontSee('name="idempotency_key"', false);
    }

    public function test_subscription_states_distinguish_paid_period_grace_and_suspension(): void
    {
        $plan = new TenantSubscription(['status' => 'active', 'period_ends_at' => now()->addDay(), 'grace_ends_at' => now()->addDays(8)]);
        $this->assertSame('فعال', PortalLabels::subscription($plan));
        $plan->period_ends_at = now()->subDay();
        $this->assertSame('در مهلت پرداخت', PortalLabels::subscription($plan));
        $plan->grace_ends_at = now()->subHour();
        $this->assertSame('متوقف‌شده؛ نیازمند تمدید', PortalLabels::subscription($plan));
        $plan->status = 'pending_activation';
        $this->assertSame('در انتظار راه‌اندازی', PortalLabels::subscription($plan));
    }
}
