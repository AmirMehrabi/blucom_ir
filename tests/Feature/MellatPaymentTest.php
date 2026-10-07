<?php

namespace Tests\Feature;

use App\Exceptions\PaymentTransportException;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\MellatPaymentService;
use App\Services\Commerce\NumberReservationService;
use App\Services\Commerce\PaymentGatewayService;
use App\Services\CustomerAccountService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\MellatFixtures;
use Tests\TestCase;

class MellatPaymentTest extends TestCase
{
    use MellatFixtures, RefreshDatabase;

    public function test_payment_request_persists_bank_order_and_converts_irt_once_before_provider_call(): void
    {
        [$order, $buyer, $number, , $fake] = $this->paymentFixture();
        $payments = app(MellatPaymentService::class);
        $key = (string) Str::uuid();
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, $key);
        $this->assertSame('redirect_ready', $attempt->status);
        $this->assertSame(250000, $attempt->business_amount);
        $this->assertSame(2500000, $attempt->gateway_amount);
        $this->assertSame('IRR', $attempt->gateway_unit);
        $this->assertSame($attempt->id, $fake->calls[0]['fields']['orderId']);
        $this->assertSame(2500000, $fake->calls[0]['fields']['amount']);
        $this->assertSame('https://my.blucom.ir/payments/mellat/callback/'.$attempt->public_id, $fake->calls[0]['fields']['callBackUrl']);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $fake->calls[0]['fields']['localTime']);
        $this->assertSame($attempt->id, $payments->initiate($buyer, $order->invoice->public_id, $key)->id);
        $this->assertSame($attempt->id, $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid())->id);
        $this->assertCount(1, $fake->calls);
        $this->assertSame($attempt->id, $order->invoice->fresh()->current_payment_attempt_id);
        $this->assertNull($number->fresh()->tenant_id);
    }

    public function test_verified_settled_callback_is_recorded_once_without_allocating_or_activating_a_line(): void
    {
        [$order, $buyer, $number, , $fake] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $payload = $this->callbackPayload($attempt);
        $result = $payments->callback($attempt->public_id, $payload);
        $this->assertSame('settled', $result->status);
        $this->assertNotNull($result->verified_at);
        $this->assertNotNull($result->settled_at);
        $this->assertSame('paid', $order->invoice->fresh()->status);
        $this->assertSame('paid_pending_allocation', $order->fresh()->status);
        $this->assertSame($attempt->id, $order->invoice->fresh()->paid_payment_attempt_id);
        $paidAt = $order->invoice->fresh()->paid_at;
        $this->assertSame('settled', $payments->callback($attempt->public_id, $payload)->status);
        $this->assertEquals($paidAt, $order->invoice->fresh()->paid_at);
        $this->assertCount(3, $fake->calls);
        $this->assertSame(['bpPayRequest', 'bpVerifyRequest', 'bpSettleRequest'], array_column($fake->calls, 'method'));
        $this->assertDatabaseCount('number_assignments', 0);
        $this->assertDatabaseCount('number_subscriptions', 0);
        $this->assertNull($number->fresh()->tenant_id);
        $this->assertDatabaseCount('inbound_routes', 0);
        $this->assertSame(1, DB::table('payment_events')->where('type', 'settlement.confirmed')->count());
    }

    public function test_forged_reference_order_or_case_and_cancellation_never_prove_payment(): void
    {
        [$order, $buyer, , , $fake] = $this->paymentFixture();
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        foreach ([['RefId' => strtolower($attempt->ref_id)], ['SaleOrderId' => '999999'], ['SaleReferenceId' => '-1']] as $change) {
            try {
                $payments->callback($attempt->public_id, [...$this->callbackPayload($attempt), ...$change]);
                $this->fail('Forged callback accepted.');
            } catch (ValidationException) {
                $this->assertSame('issued', $order->invoice->fresh()->status);
            }
        }
        $payments->callback($attempt->public_id, [...$this->callbackPayload($attempt), 'ResCode' => '17']);
        $this->assertSame('redirect_ready', $attempt->fresh()->status);
        $this->assertCount(1, $fake->calls);
    }

    public function test_unknown_initiation_never_reissues_a_charge_even_with_a_new_key(): void
    {
        [$order, $buyer, , $admin, $fake] = $this->paymentFixture([new PaymentTransportException('Synthetic timeout')]);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->assertSame('unknown', $attempt->status);
        $this->assertSame($attempt->id, $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid())->id);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertCount(1, $fake->calls);
        $this->expectException(ValidationException::class);
        $payments->reconcile($admin, $attempt->id, 'Synthetic operator investigation');
    }

    public function test_definitive_request_rejection_allows_a_fresh_attempt_with_a_new_bank_order(): void
    {
        [$order, $buyer, , , $fake] = $this->paymentFixture(['24', '0,SyntheticOtherRef']);
        $payments = app(MellatPaymentService::class);
        $old = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->assertSame('initiation_failed', $old->status);
        $new = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->assertNotSame($old->id, $new->id);
        $this->assertSame('redirect_ready', $new->status);
        $this->assertCount(2, $fake->calls);
    }

    public function test_timeout_after_verification_is_reconciled_without_verifying_or_paying_again(): void
    {
        [$order, $buyer, , $admin, $fake] = $this->paymentFixture(['0,SyntheticCaseRef', '0', new PaymentTransportException('Synthetic timeout'), '46', '45']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $pending = $payments->callback($attempt->public_id, $this->callbackPayload($attempt));
        $this->assertSame('pending_settlement', $pending->status);
        $this->assertNotNull($pending->verified_at);
        $this->assertNull($pending->settled_at);
        $this->assertSame('issued', $order->invoice->fresh()->status);
        $settled = $payments->reconcile($admin, $attempt->id, 'Synthetic settlement retry');
        $this->assertSame('settled', $settled->status);
        $this->assertSame(['bpPayRequest', 'bpVerifyRequest', 'bpSettleRequest', 'bpInquiryRequest', 'bpSettleRequest'], array_column($fake->calls, 'method'));
        $this->assertDatabaseHas('commerce_audit_events', ['event' => 'payment.reconciliation_requested', 'actor_user_id' => $admin->id]);
    }

    public function test_already_verified_and_settled_codes_require_provider_inquiry_and_never_reverse(): void
    {
        [$order, $buyer, , , $fake] = $this->paymentFixture(['0,SyntheticCaseRef', '43', '45']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->assertSame('settled', $payments->callback($attempt->public_id, $this->callbackPayload($attempt))->status);
        $this->assertSame(['bpPayRequest', 'bpVerifyRequest', 'bpInquiryRequest'], array_column($fake->calls, 'method'));
    }

    public function test_unverified_bad_sale_reference_is_not_frozen_and_a_genuine_callback_can_retry(): void
    {
        [$order, $buyer] = $this->paymentFixture(['0,SyntheticCaseRef', '42', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $bad = $payments->callback($attempt->public_id, $this->callbackPayload($attempt, '111111'));
        $this->assertNull($bad->sale_reference);
        $this->assertNull($bad->candidate_sale_reference);
        $this->assertSame('redirect_ready', $bad->status);
        $this->assertSame('settled', $payments->callback($attempt->public_id, $this->callbackPayload($attempt, '7654321'))->status);
    }

    public function test_disable_and_credential_rotation_do_not_break_an_existing_callback(): void
    {
        [$order, $buyer, , $admin, $fake, $gateway] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        app(PaymentGatewayService::class)->update($admin, 'mellat', ['revision' => 1, 'enabled' => false, 'amount_unit_confirmed' => true, 'merchant_password' => 'synthetic-rotated']);
        config(['commerce.checkout_enabled' => false]);
        $this->assertSame('settled', $payments->callback($attempt->public_id, $this->callbackPayload($attempt))->status);
        $this->assertNotSame($attempt->payment_gateway_version_id, $gateway->fresh()->current_version_id);
        $this->assertSame([$attempt->payment_gateway_version_id], array_values(array_unique(array_column($fake->calls, 'version_id'))));
    }

    public function test_late_success_preserves_the_new_buyers_hold_and_records_paid_unfulfilled(): void
    {
        [$order, $buyer, $number, , $fake] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->travel(16)->minutes();
        app(NumberReservationService::class)->expire($order->reservation->id);
        $other = $this->buyer('002');
        $checkout = app(CheckoutService::class);
        $quote = $checkout->quote($other, $number->current_offer_id);
        $new = $checkout->reserve($other, $number->current_offer_id, $quote, (string) Str::uuid());
        $this->assertSame('settled', $payments->callback($attempt->public_id, $this->callbackPayload($attempt))->status);
        $this->assertSame('paid_unfulfilled', $order->fresh()->status);
        $this->assertSame('paid', $order->invoice->fresh()->status);
        $this->assertSame($new->reservation->id, $number->fresh()->current_reservation_id);
        $this->assertSame('reserved', $new->fresh()->status);
        $this->assertNull($number->fresh()->tenant_id);
    }

    public function test_expiry_after_settlement_keeps_paid_evidence_and_marks_unfulfilled(): void
    {
        [$order, $buyer, $number] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $payments->callback($attempt->public_id, $this->callbackPayload($attempt));
        $this->travel(16)->minutes();
        $this->assertTrue(app(NumberReservationService::class)->expire($order->reservation->id));
        $this->assertSame('paid_unfulfilled', $order->fresh()->status);
        $this->assertSame('paid', $order->invoice->fresh()->status);
        $this->assertSame('settled', $attempt->fresh()->status);
        $this->assertNull($number->fresh()->current_reservation_id);
    }

    public function test_amount_or_merchant_correlation_failure_never_calls_provider_or_marks_paid(): void
    {
        [$order, $buyer, , , $fake] = $this->paymentFixture();
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        DB::table('payment_attempts')->where('id', $attempt->id)->update(['gateway_amount' => 1]);
        $this->assertSame('unknown', $payments->callback($attempt->public_id, $this->callbackPayload($attempt))->status);
        $this->assertCount(1, $fake->calls);
        $this->assertSame('issued', $order->invoice->fresh()->status);
    }

    public function test_an_active_operation_lease_stops_duplicate_callbacks(): void
    {
        [$order, $buyer, , , $fake] = $this->paymentFixture();
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $attempt->update(['status' => 'verifying', 'operation_token' => (string) Str::uuid(), 'operation_expires_at' => now()->addMinute()]);
        $this->assertSame('verifying', $payments->callback($attempt->public_id, $this->callbackPayload($attempt))->status);
        $this->assertCount(1, $fake->calls);
    }

    public function test_foreign_invoices_revoked_permissions_and_disabled_gateway_cannot_start_payment(): void
    {
        [$order, $buyer, , $admin, $fake] = $this->paymentFixture();
        $other = $this->buyer('002');
        $this->actingAs($other, 'customer')->post('https://my.blucom.ir/invoices/'.$order->invoice->public_id.'/payments', ['idempotency_key' => (string) Str::uuid()])->assertNotFound();
        app(CustomerAccountService::class)->setPermissions($buyer, []);
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/invoices/'.$order->invoice->public_id.'/payments', ['idempotency_key' => (string) Str::uuid()])->assertForbidden();
        $this->assertCount(0, $fake->calls);
    }

    public function test_anonymous_callback_on_customer_host_works_without_session_and_drops_card_fields(): void
    {
        [$order, $buyer] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->post($payments->callbackUrl($attempt), [...$this->callbackPayload($attempt), 'CardHolderPan' => 'synthetic-card-data', 'CardHolderInfo' => 'synthetic-holder'])
            ->assertOk()->assertDontSee($order->invoice->invoice_number)->assertDontSee('synthetic-card-data');
        $this->assertSame('paid', $order->invoice->fresh()->status);
        $this->assertStringNotContainsString('synthetic-card-data', json_encode(DB::table('payment_events')->get()));
        $this->post('https://admin.blucom.ir/payments/mellat/callback/'.$attempt->public_id, $this->callbackPayload($attempt))->assertNotFound();
    }

    public function test_admin_can_reverse_only_a_bank_confirmed_unsettled_attempt(): void
    {
        [$order, $buyer, , $admin, $fake] = $this->paymentFixture(['0,SyntheticCaseRef', '0', new PaymentTransportException('Synthetic timeout'), '46', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $payments->callback($attempt->public_id, $this->callbackPayload($attempt));
        $result = $payments->reverse($admin, $attempt->id, 'Synthetic explicit reversal');
        $this->assertSame('reversed', $result->status);
        $this->assertNull($result->settled_at);
        $this->assertSame('issued', $order->invoice->fresh()->status);
        $this->assertSame('bpReversalRequest', $fake->calls[4]['method']);
        $this->assertDatabaseHas('commerce_audit_events', ['event' => 'payment.reversal_requested', 'actor_user_id' => $admin->id]);
    }

    public function test_reversal_never_undoes_settled_or_ambiguous_provider_success(): void
    {
        [$order, $buyer, , $admin, $fake] = $this->paymentFixture(['0,SyntheticCaseRef', '0', new PaymentTransportException('Synthetic timeout'), '0', '45']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $payments->callback($attempt->public_id, $this->callbackPayload($attempt));
        $this->assertSame('pending_settlement', $payments->reverse($admin, $attempt->id, 'Synthetic investigation')->status);
        $this->assertSame('settled', $payments->reverse($admin, $attempt->id, 'Synthetic final inquiry')->status);
        $this->assertNotContains('bpReversalRequest', array_column($fake->calls, 'method'));
        $this->expectException(ValidationException::class);
        $payments->reverse($admin, $attempt->id, 'Settled payment must not reverse');
    }

    public function test_ambiguous_reversal_retry_inquires_without_settling_the_payment(): void
    {
        [$order, $buyer, , $admin, $fake] = $this->paymentFixture(['0,SyntheticCaseRef', '0', new PaymentTransportException('Synthetic timeout'), '46', new PaymentTransportException('Synthetic reversal timeout'), '46', '48']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $payments->callback($attempt->public_id, $this->callbackPayload($attempt));
        $this->assertSame('unknown', $payments->reverse($admin, $attempt->id, 'Synthetic reversal')->status);
        $this->assertSame('unknown', $payments->reconcile($admin, $attempt->id, 'Synthetic inquiry')->status);
        $this->assertSame('reversed', $payments->reconcile($admin, $attempt->id, 'Synthetic final inquiry')->status);
        $this->assertSame(1, count(array_filter(array_column($fake->calls, 'method'), fn ($method) => $method === 'bpSettleRequest')));
        $this->assertNull($order->invoice->fresh()->paid_payment_attempt_id);
    }

    public function test_only_the_callback_bypasses_csrf_and_inactive_browser_sessions_cannot_block_it(): void
    {
        [$order, $buyer, , $admin] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->app['env'] = 'production';
        $this->actingAs($admin)->put('/admin/settings/payment-gateways/mellat', ['revision' => 1])->assertStatus(419);
        $this->actingAs($buyer, 'customer')->post('https://my.blucom.ir/invoices/'.$order->invoice->public_id.'/payments', ['idempotency_key' => (string) Str::uuid()])->assertStatus(419);
        $buyer->update(['disabled_at' => now()]);
        $this->post($payments->callbackUrl($attempt), $this->callbackPayload($attempt))->assertOk();
        $this->assertSame('paid', $order->invoice->fresh()->status);
    }

    #[DataProvider('amounts')]
    public function test_small_and_large_supported_irt_amounts_are_converted_exactly_once(int $amount): void
    {
        [$order, $buyer, , , $fake] = $this->paymentFixture(['0,SyntheticCaseRef'], 1, $amount);
        $attempt = app(MellatPaymentService::class)->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->assertSame($amount, $attempt->business_amount);
        $this->assertSame($amount * 10, $fake->calls[0]['fields']['amount']);
    }

    public static function amounts(): array
    {
        return [[1], [1000000000000]];
    }

    #[DataProvider('corruptCorrelations')]
    public function test_wrong_currency_unit_or_merchant_never_reaches_verification(array $change): void
    {
        [$order, $buyer, , , $fake] = $this->paymentFixture();
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        DB::table('payment_attempts')->where('id', $attempt->id)->update($change);
        $this->assertSame('unknown', $payments->callback($attempt->public_id, $this->callbackPayload($attempt))->status);
        $this->assertCount(1, $fake->calls);
        $this->assertNull($order->invoice->fresh()->paid_at);
    }

    public static function corruptCorrelations(): array
    {
        return [[['account_key' => 'synthetic-wrong-account']], [['gateway_unit' => 'IRT']], [['business_currency' => 'IRR']], [['business_amount' => PHP_INT_MAX]]];
    }

    public function test_paid_invoice_cannot_be_overwritten_by_another_successful_attempt(): void
    {
        [$order, $buyer, , , $fake] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        // Represents another historical request discovered during merchant investigation.
        $other = $attempt->replicate();
        $other->fill(['public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid(), 'ref_id' => 'SyntheticOtherRef']);
        $other->save();
        $payments->callback($attempt->public_id, $this->callbackPayload($attempt));
        $paidAt = $order->invoice->fresh()->paid_at;
        $this->assertSame('duplicate_payment', $payments->callback($other->public_id, $this->callbackPayload($other, '7654322'))->status);
        $this->assertSame($attempt->id, $order->invoice->fresh()->paid_payment_attempt_id);
        $this->assertEquals($paidAt, $order->invoice->fresh()->paid_at);
        $this->assertSame(1, DB::table('payment_events')->where('type', 'settlement.confirmed')->count());
        $this->assertSame(1, DB::table('payment_events')->where('type', 'settlement.duplicate')->count());
    }

    public function test_stale_verification_worker_cannot_settle_after_another_operation_takes_its_lease(): void
    {
        [$order, $buyer, , , $fake] = $this->paymentFixture();
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $newToken = (string) Str::uuid();
        $fake->responses = [function () use ($attempt, $newToken) {
            $attempt->fresh()->update(['status' => 'unknown', 'operation_token' => $newToken, 'operation_expires_at' => now()->addMinutes(2)]);

            return '0';
        }];
        $result = $payments->callback($attempt->public_id, $this->callbackPayload($attempt));
        $this->assertSame($newToken, $result->operation_token);
        $this->assertNull($result->verified_at);
        $this->assertCount(2, $fake->calls);
        $this->assertNull($order->invoice->fresh()->paid_at);
    }

    public function test_gateway_disabled_checkout_disabled_or_invalidated_readiness_cannot_initiate(): void
    {
        [$order, $buyer, $number, $admin, $fake] = $this->paymentFixture();
        $url = 'https://my.blucom.ir/invoices/'.$order->invoice->public_id.'/payments';
        config(['commerce.checkout_enabled' => false]);
        $this->actingAs($buyer, 'customer')->post($url, ['idempotency_key' => (string) Str::uuid()])->assertNotFound();
        config(['commerce.checkout_enabled' => true]);
        app(PaymentGatewayService::class)->update($admin, 'mellat', ['revision' => 1, 'enabled' => false, 'amount_unit_confirmed' => true]);
        $this->post($url, ['idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('payment');
        app(PaymentGatewayService::class)->update($admin, 'mellat', ['revision' => 2, 'enabled' => true, 'amount_unit_confirmed' => true]);
        // Simulate operational drift; normal admin mutation is guarded while published.
        DB::table('sip_numbers')->where('id', $number->id)->update(['enabled' => false]);
        $this->post($url, ['idempotency_key' => (string) Str::uuid()])->assertSessionHasErrors('payment');
        $this->assertCount(0, $fake->calls);
        $this->assertDatabaseCount('payment_attempts', 0);
    }

    public function test_billing_view_without_purchase_authority_does_not_reveal_a_bank_continue_token(): void
    {
        [$order, $buyer] = $this->paymentFixture();
        $attempt = app(MellatPaymentService::class)->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        app(CustomerAccountService::class)->setPermissions($buyer, [Permissions::BILLING_VIEW]);
        $this->actingAs($buyer, 'customer')->get('https://my.blucom.ir/payments/'.$attempt->public_id)
            ->assertOk()->assertDontSee($attempt->ref_id)->assertDontSee('name="RefId"', false);
    }

    public function test_verified_financial_facts_cannot_be_erased(): void
    {
        [$order, $buyer] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0']);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $attempt = $payments->callback($attempt->public_id, $this->callbackPayload($attempt));
        foreach ([['sale_reference' => null], ['verified_at' => null], ['settled_at' => null]] as $change) {
            try {
                $attempt->fresh()->update($change);
                $this->fail('Verified payment evidence was erased.');
            } catch (ValidationException) {
                $this->assertNotNull($attempt->fresh()->settled_at);
            }
        }
        $this->expectException(ValidationException::class);
        $order->invoice->fresh()->update(['paid_payment_attempt_id' => null, 'paid_at' => null]);
    }
}
