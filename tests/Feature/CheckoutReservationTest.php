<?php

namespace Tests\Feature;

use App\Models\CommerceInvoice;
use App\Models\CommerceOrder;
use App\Models\NumberReservation;
use App\Models\PaymentAttempt;
use App\Models\SipNumber;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\NumberOfferService;
use App\Services\Commerce\NumberReservationService;
use App\Services\CustomerAccountService;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CheckoutFixtures;
use Tests\TestCase;

class CheckoutReservationTest extends TestCase
{
    use CheckoutFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['commerce.catalog_enabled' => true, 'commerce.reservation_enabled' => true]);
    }

    private function checkout(): array
    {
        [$offer, $number, $admin, $gateway, $version] = $this->offer();
        $buyer = $this->buyer();
        $service = app(CheckoutService::class);
        $quote = $service->quote($buyer, $offer->id);
        $key = (string) Str::uuid();
        $order = $service->reserve($buyer, $offer->id, $quote, $key);

        return [$order, $buyer, $offer, $number, $admin, $quote, $key];
    }

    public function test_reservation_is_atomic_irt_proforma_and_does_not_assign_or_route_calls(): void
    {
        [$order, $buyer, $offer, $number] = $this->checkout();
        $number = $number->fresh();
        $this->assertSame('reserved', $number->inventory_state);
        $this->assertSame($order->reservation->id, $number->current_reservation_id);
        $this->assertSame($offer->id, $number->current_offer_id);
        $this->assertNull($number->tenant_id);
        $this->assertNull($number->current_assignment_id);
        $this->assertFalse($number->isRoutable());
        $this->assertSame('proforma', $order->invoice->kind);
        $this->assertSame('IRT', $order->currency);
        $this->assertSame(250000, $order->total_amount);
        $this->assertSame($order->item->snapshot, $order->invoice->item->snapshot);
        $this->assertSame(5, $order->invoice->item->snapshot['limits']['extensions']);
        $this->assertSame('tenant', $order->invoice->item->snapshot['limit_scope']);
        $this->assertSame(15, (int) round(now()->diffInMinutes($order->expires_at)));
        $this->assertDatabaseCount('inbound_routes', 0);
        $this->assertDatabaseCount('outbound_routes', 0);
        $this->assertDatabaseCount('payment_attempts', 0);
        $this->assertDatabaseCount('number_assignments', 0);
        $this->assertDatabaseCount('number_subscriptions', 0);
        $this->assertDatabaseHas('commerce_audit_events', ['event' => 'reservation.created', 'actor_customer_id' => $buyer->id, 'actor_user_id' => null]);
        $this->assertArrayNotHasKey('provider_gateway_id', $order->item->snapshot);
        $this->assertArrayNotHasKey('password', $order->item->snapshot);
    }

    public function test_same_key_replays_the_original_order_without_extending_expiry_even_after_expiration(): void
    {
        [$order, $buyer, $offer, , , $quote, $key] = $this->checkout();
        $this->travel(3)->minutes();
        $retry = app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, strtoupper($key));
        $this->assertSame($order->id, $retry->id);
        $this->assertEquals($order->expires_at, $retry->expires_at);
        $this->travel(13)->minutes();
        app(NumberReservationService::class)->expire($order->reservation->id);
        $retry = app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, $key);
        $this->assertSame('expired', $retry->status);
        $this->assertDatabaseCount('commerce_orders', 1);
        $this->assertDatabaseCount('commerce_invoices', 1);
        $this->assertDatabaseCount('number_reservations', 1);
    }

    public function test_key_reuse_with_a_different_quote_is_rejected(): void
    {
        [$order, $buyer, $offer, , , $quote, $key] = $this->checkout();
        $quote['monthly_amount']++;
        $this->expectException(ValidationException::class);
        app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, $key);
    }

    public function test_another_buyer_or_a_new_key_cannot_duplicate_a_live_hold(): void
    {
        [$order, , $offer, , , $quote] = $this->checkout();
        $buyer = $this->buyer('002');
        try {
            app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, (string) Str::uuid());
            $this->fail('Competing reservation accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('commerce_orders', 1);
            $this->assertDatabaseCount('commerce_invoices', 1);
        }
    }

    public function test_expiry_is_terminal_idempotent_and_a_new_buyer_can_reserve_without_erasing_history(): void
    {
        [$old, , $offer, $number, , $quote] = $this->checkout();
        $this->travel(16)->minutes();
        $buyer = $this->buyer('002');
        $new = app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, (string) Str::uuid());
        $this->assertSame('expired', $old->fresh()->status);
        $this->assertSame('expired', $old->invoice->fresh()->status);
        $this->assertSame($new->reservation->id, $number->fresh()->current_reservation_id);
        $this->assertFalse(app(NumberReservationService::class)->expire($old->reservation->id));
        $this->assertSame($new->reservation->id, $number->fresh()->current_reservation_id);
        $this->assertDatabaseCount('commerce_orders', 2);
        $this->assertDatabaseCount('commerce_invoices', 2);
        $this->assertDatabaseHas('commerce_audit_events', ['event' => 'reservation.expired', 'actor_customer_id' => null, 'actor_user_id' => null]);
    }

    public function test_expiry_command_preserves_payment_evidence_and_marks_uncertainty_for_reconciliation(): void
    {
        [$order] = $this->checkout();
        $attempt = PaymentAttempt::query()->create([
            'commerce_invoice_id' => $order->invoice->id, 'idempotency_key' => (string) Str::uuid(),
            'provider' => 'mellat', 'account_key' => 'synthetic-test', 'business_amount' => 250000,
            'business_currency' => 'IRT', 'gateway_amount' => 2500000, 'gateway_unit' => 'IRR', 'status' => 'unknown',
        ]);
        $this->travel(16)->minutes();
        $this->artisan('commerce:expire-reservations')->expectsOutput('Expired: 1; requires review: 0.')->assertSuccessful();
        $this->assertSame('reconciliation_required', $order->fresh()->status);
        $this->assertSame('reconciliation_required', $order->invoice->fresh()->status);
        $this->assertSame('unknown', $attempt->fresh()->status);
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->artisan('commerce:expire-reservations')->expectsOutput('Expired: 0; requires review: 0.')->assertSuccessful();
        $this->artisan('commerce:expire-reservations', ['--limit' => 0])->assertExitCode(2);
    }

    public function test_stale_price_or_version_is_rejected_without_partial_records(): void
    {
        [$offer] = $this->offer();
        $buyer = $this->buyer();
        $quote = app(CheckoutService::class)->quote($buyer, $offer->id);
        $quote['monthly_amount'] = 1;
        try {
            app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, (string) Str::uuid());
            $this->fail('Stale quote accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('commerce_orders', 0);
            $this->assertDatabaseCount('commerce_invoices', 0);
            $this->assertDatabaseCount('number_reservations', 0);
        }
    }

    public function test_withdrawn_offers_cannot_be_reserved_and_holds_cannot_be_withdrawn(): void
    {
        [$order, $buyer, $offer, $number, $admin, $quote] = $this->checkout();
        try {
            app(NumberOfferService::class)->withdraw($admin, $number->id, $number->fresh()->inventory_revision, 'Test withdrawal');
            $this->fail('Live hold withdrawn.');
        } catch (ValidationException) {
            $this->assertNull($offer->fresh()->withdrawn_at);
        }
        $this->travel(16)->minutes();
        app(NumberReservationService::class)->expire($order->reservation->id);
        app(NumberOfferService::class)->withdraw($admin, $number->id, $number->fresh()->inventory_revision, 'Test withdrawal');
        $this->expectException(ValidationException::class);
        app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, (string) Str::uuid());
    }

    public function test_fresh_permissions_and_membership_are_required_even_for_an_idempotent_retry(): void
    {
        [$order, $buyer, $offer, , , $quote, $key] = $this->checkout();
        app(CustomerAccountService::class)->setPermissions($buyer, [Permissions::BILLING_VIEW]);
        $this->expectException(HttpException::class);
        app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, $key);
    }

    public function test_foreign_tenant_order_is_not_visible_and_billing_view_is_required(): void
    {
        [$order, $buyer] = $this->checkout();
        $this->assertSame($order->id, app(CheckoutService::class)->order($buyer, $order->public_id)->id);
        try {
            app(CheckoutService::class)->order($this->buyer('002'), $order->public_id);
            $this->fail('Foreign order exposed.');
        } catch (ModelNotFoundException) {
            $this->assertTrue(true);
        }
        app(CustomerAccountService::class)->setPermissions($buyer, [Permissions::NUMBERS_PURCHASE]);
        $this->expectException(HttpException::class);
        app(CheckoutService::class)->order($buyer, $order->public_id);
    }

    public function test_staff_without_purchase_permission_and_disabled_accounts_are_denied(): void
    {
        [$offer] = $this->offer();
        $owner = $this->buyer();
        $staff = app(CustomerAccountService::class)->createStaff($owner->tenant, 'Synthetic staff', '+989120000002', Permissions::CUSTOMER_STAFF_DEFAULTS);
        try {
            app(CheckoutService::class)->quote($staff, $offer->id);
            $this->fail('Staff allowed to purchase.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $owner->update(['disabled_at' => now()]);
        $this->expectException(HttpException::class);
        app(CheckoutService::class)->quote($owner, $offer->id);
    }

    public function test_flags_and_invalid_duration_fail_closed(): void
    {
        [$offer] = $this->offer();
        $buyer = $this->buyer();
        $quote = app(CheckoutService::class)->quote($buyer, $offer->id);
        config(['commerce.reservation_enabled' => false]);
        try {
            app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, (string) Str::uuid());
            $this->fail('Disabled reservation accepted.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
        config(['commerce.reservation_enabled' => true, 'commerce.reservation_minutes' => 0]);
        $this->expectException(ValidationException::class);
        app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, (string) Str::uuid());
    }

    public function test_issued_financial_fields_are_immutable_and_cannot_be_deleted(): void
    {
        [$order] = $this->checkout();
        foreach ([$order, $order->item, $order->invoice, $order->invoice->item, $order->reservation] as $record) {
            try {
                $record->update([$record instanceof NumberReservation ? 'tenant_id' : ($record instanceof CommerceOrder || $record instanceof CommerceInvoice ? 'total_amount' : 'amount') => 1]);
                $this->fail('Financial identity/snapshot updated.');
            } catch (ValidationException) {
                $record->refresh();
            }
            try {
                $record->delete();
                $this->fail('Financial history deleted.');
            } catch (ValidationException) {
                $this->assertTrue($record->fresh()->exists);
            }
        }
    }

    public function test_database_foreign_keys_retain_purchaser_and_financial_history(): void
    {
        [$order, $buyer] = $this->checkout();
        $this->expectException(QueryException::class);
        DB::table('customers')->where('id', $buyer->id)->delete();
    }

    public function test_catalog_changes_and_repurchase_do_not_rewrite_an_expired_invoice(): void
    {
        [$order, $buyer, $offer, $number, $admin] = $this->checkout();
        $snapshot = $order->invoice->item->snapshot;
        $this->travel(16)->minutes();
        app(NumberReservationService::class)->expire($order->reservation->id);
        $offers = app(NumberOfferService::class);
        $offers->withdraw($admin, $number->id, $number->fresh()->inventory_revision, 'New price');
        $new = $offers->publish($admin, $number->id, $number->fresh()->inventory_revision, $offer->plan_version_id, 300000);
        $quote = app(CheckoutService::class)->quote($buyer, $new->id);
        app(CheckoutService::class)->reserve($buyer, $new->id, $quote, (string) Str::uuid());
        $this->assertSame($snapshot, $order->invoice->item->fresh()->snapshot);
        $this->assertSame(250000, $order->invoice->fresh()->total_amount);
    }

    public function test_malformed_current_hold_is_never_cleared_or_sold(): void
    {
        [$order, $buyer, $offer, $number, , $quote] = $this->checkout();
        $this->travel(16)->minutes();
        // Simulate a bad projection without going through the business services.
        DB::table('sip_numbers')->where('id', $number->id)->update(['inventory_state' => 'available']);
        $this->artisan('commerce:expire-reservations')->expectsOutput('Expired: 0; requires review: 1.')->assertFailed();
        $this->assertSame($order->reservation->id, $number->fresh()->current_reservation_id);
        $this->expectException(ValidationException::class);
        app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, (string) Str::uuid());
    }

    public function test_changed_technical_review_or_plan_eligibility_fails_closed_at_reservation(): void
    {
        [$offer, $number, , $gateway, $version] = $this->offer();
        $buyer = $this->buyer();
        $service = app(CheckoutService::class);
        $quote = $service->quote($buyer, $offer->id);
        foreach ([
            ['sip_numbers', $number->id, 'enabled', false],
            ['sip_numbers', $number->id, 'readiness_fingerprint', 'invalid-review'],
            ['sip_gateways', $gateway->id, 'approved_for_outbound', false],
            ['sip_gateways', $gateway->id, 'context', 'default'],
            ['plans', $version->plan_id, 'archived', true],
            ['plan_versions', $version->id, 'published_at', null],
            ['plan_versions', $version->id, 'billing_interval', 'yearly'],
            ['number_offers', $offer->id, 'currency', 'IRR'],
        ] as [$table, $id, $field, $value]) {
            $original = DB::table($table)->where('id', $id)->value($field);
            DB::table($table)->where('id', $id)->update([$field => $value]);
            try {
                $service->reserve($buyer, $offer->id, $quote, (string) Str::uuid());
                $this->fail('Ineligible configuration accepted: '.$field);
            } catch (ValidationException) {
                $this->assertDatabaseCount('commerce_orders', 0);
                $this->assertNull($number->fresh()->current_reservation_id);
            } finally {
                DB::table($table)->where('id', $id)->update([$field => $original]);
            }
        }
    }

    public function test_stale_customer_model_cannot_use_disabled_or_foreign_tenant_membership(): void
    {
        [$offer] = $this->offer();
        $buyer = $this->buyer();
        $other = $this->buyer('002');
        DB::table('customers')->where('id', $buyer->id)->update(['tenant_id' => $other->tenant_id]);
        $this->expectException(HttpException::class);
        app(CheckoutService::class)->quote($buyer, $offer->id);
    }

    public function test_failure_after_invoice_creation_rolls_back_the_entire_checkout(): void
    {
        [$offer, $number] = $this->offer();
        $buyer = $this->buyer();
        $quote = app(CheckoutService::class)->quote($buyer, $offer->id);
        NumberReservation::creating(fn () => throw new \RuntimeException('Synthetic failure'));
        try {
            app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, (string) Str::uuid());
            $this->fail('Synthetic reservation failure did not fire.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Synthetic failure', $e->getMessage());
            $this->assertDatabaseCount('commerce_orders', 0);
            $this->assertDatabaseCount('commerce_invoices', 0);
            $this->assertDatabaseCount('commerce_order_items', 0);
            $this->assertDatabaseCount('commerce_invoice_items', 0);
            $this->assertNull($number->fresh()->current_reservation_id);
            $this->assertSame('available', $number->fresh()->inventory_state);
        } finally {
            NumberReservation::flushEventListeners();
            NumberReservation::clearBootedModels();
        }
    }

    public function test_invalid_key_cannot_be_overridden_by_a_field_in_the_quote(): void
    {
        [$offer] = $this->offer();
        $buyer = $this->buyer();
        $quote = app(CheckoutService::class)->quote($buyer, $offer->id);
        $quote['key'] = (string) Str::uuid();
        $this->expectException(ValidationException::class);
        app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, 'invalid');
    }

    public function test_bank_references_are_unique_within_a_provider_account(): void
    {
        [$order] = $this->checkout();
        $fields = [
            'commerce_invoice_id' => $order->invoice->id, 'provider' => 'mellat', 'account_key' => 'synthetic-test',
            'ref_id' => 'synthetic-reference', 'business_amount' => 250000, 'business_currency' => 'IRT',
            'gateway_amount' => 2500000, 'gateway_unit' => 'IRR',
        ];
        PaymentAttempt::query()->create([...$fields, 'idempotency_key' => (string) Str::uuid()]);
        $this->expectException(QueryException::class);
        PaymentAttempt::query()->create([...$fields, 'idempotency_key' => (string) Str::uuid()]);
    }

    public function test_provider_reference_cannot_be_replaced_after_it_is_recorded(): void
    {
        [$order] = $this->checkout();
        $attempt = PaymentAttempt::query()->create([
            'commerce_invoice_id' => $order->invoice->id, 'idempotency_key' => (string) Str::uuid(),
            'provider' => 'mellat', 'account_key' => 'synthetic-test', 'business_amount' => 250000,
            'business_currency' => 'IRT', 'gateway_amount' => 2500000, 'gateway_unit' => 'IRR',
        ]);
        $attempt->update(['ref_id' => 'synthetic-original']);
        $this->expectException(ValidationException::class);
        $attempt->update(['ref_id' => 'synthetic-replacement']);
    }

    public function test_schema_rollback_refuses_to_discard_issued_records(): void
    {
        [$order] = $this->checkout();
        $migration = require database_path('migrations/2026_10_07_000002_create_checkout_records.php');
        try {
            $migration->down();
            $this->fail('Financial schema was discarded.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Commerce records require an explicit retention plan before rollback.', $e->getMessage());
            $this->assertSame($order->id, $order->fresh()->id);
            $this->assertSame($order->reservation->id, SipNumber::findOrFail($order->item->sip_number_id)->current_reservation_id);
        }
    }
}
