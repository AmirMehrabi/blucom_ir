<?php

namespace Tests\Integration;

use App\Models\CommerceOrder;
use App\Models\Customer;
use App\Models\SipNumber;
use App\Models\User;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\NumberOfferService;
use App\Services\Commerce\NumberReservationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\CheckoutFixtures;
use Tests\TestCase;

/** Only opt-in disposable MySQL/MariaDB databases may run destructive race fixtures. */
class CheckoutReservationConcurrencyTest extends TestCase
{
    use CheckoutFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('CHECKOUT_CONCURRENCY_TESTS') !== '1' || config('database.default') !== 'mysql'
            || ! str_starts_with((string) config('database.connections.mysql.database'), 'blucom_checkout_test')
            || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Opt-in disposable MySQL database and pcntl required.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        config(['commerce.catalog_enabled' => true, 'commerce.reservation_enabled' => true]);
    }

    private function race(array $operations): array
    {
        // Disconnect before fork so processes never share a PDO socket.
        DB::purge('mysql');
        $children = [];
        foreach ($operations as $operation) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
            $pid = pcntl_fork();
            if ($pid === 0) {
                fclose($pair[0]);
                fread($pair[1], 1);
                DB::purge('mysql');
                try {
                    $operation();
                    $result = 'accepted';
                } catch (ValidationException) {
                    $result = 'rejected';
                } catch (\Throwable $exception) {
                    $result = 'unexpected:'.$exception::class;
                }
                fwrite($pair[1], $result);
                fclose($pair[1]);
                exit(0);
            }
            $this->assertGreaterThan(0, $pid);
            fclose($pair[1]);
            stream_set_timeout($pair[0], 15);
            $children[] = [$pid, $pair[0]];
        }
        foreach ($children as [, $socket]) {
            fwrite($socket, 'S');
        }
        $results = [];
        foreach ($children as [$pid, $socket]) {
            $results[] = stream_get_contents($socket);
            fclose($socket);
            pcntl_waitpid($pid, $status);
            $this->assertSame(0, pcntl_wexitstatus($status));
        }
        DB::purge('mysql');

        return $results;
    }

    public function test_competing_customers_get_one_hold_invoice_and_order(): void
    {
        [$offer, $number] = $this->offer();
        $a = $this->buyer();
        $b = $this->buyer('002');
        $quote = app(CheckoutService::class)->quote($a, $offer->id);
        $results = $this->race([
            fn () => app(CheckoutService::class)->reserve(Customer::findOrFail($a->id), $offer->id, $quote, (string) Str::uuid()),
            fn () => app(CheckoutService::class)->reserve(Customer::findOrFail($b->id), $offer->id, $quote, (string) Str::uuid()),
        ]);
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $this->assertDatabaseCount('commerce_orders', 1);
        $this->assertDatabaseCount('commerce_invoices', 1);
        $this->assertDatabaseCount('number_reservations', 1);
        $this->assertSame(CommerceOrder::firstOrFail()->reservation->id, $number->fresh()->current_reservation_id);
        $this->assertNull($number->fresh()->tenant_id);
    }

    public function test_same_customer_same_key_is_serialized_to_one_order(): void
    {
        [$offer] = $this->offer();
        $buyer = $this->buyer();
        $quote = app(CheckoutService::class)->quote($buyer, $offer->id);
        $key = (string) Str::uuid();
        $reserve = fn () => app(CheckoutService::class)->reserve(Customer::findOrFail($buyer->id), $offer->id, $quote, $key);
        $this->assertSame(['accepted', 'accepted'], $this->race([$reserve, $reserve]));
        $this->assertDatabaseCount('commerce_orders', 1);
        $this->assertDatabaseCount('commerce_invoices', 1);
        $this->assertDatabaseCount('number_reservations', 1);
    }

    public function test_withdrawal_and_reservation_are_mutually_exclusive(): void
    {
        [$offer, $number, $admin] = $this->offer();
        $buyer = $this->buyer();
        $quote = app(CheckoutService::class)->quote($buyer, $offer->id);
        $results = $this->race([
            fn () => app(CheckoutService::class)->reserve(Customer::findOrFail($buyer->id), $offer->id, $quote, (string) Str::uuid()),
            fn () => app(NumberOfferService::class)->withdraw(User::findOrFail($admin->id), $number->id, $number->inventory_revision, 'Synthetic race withdrawal'),
        ]);
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $number = $number->fresh();
        if ($number->current_reservation_id !== null) {
            $this->assertNull($offer->fresh()->withdrawn_at);
            $this->assertSame('reserved', $number->inventory_state);
        } else {
            $this->assertNotNull($offer->fresh()->withdrawn_at);
            $this->assertDatabaseCount('commerce_orders', 0);
        }
    }

    public function test_expiry_worker_and_new_reservation_cannot_release_the_new_hold(): void
    {
        [$offer, $number] = $this->offer();
        $buyer = $this->buyer();
        $quote = app(CheckoutService::class)->quote($buyer, $offer->id);
        $old = app(CheckoutService::class)->reserve($buyer, $offer->id, $quote, (string) Str::uuid());
        $other = $this->buyer('002');
        // Keep production records immutable; advance the application clock in both forked processes.
        $this->travel(16)->minutes();
        $results = $this->race([
            fn () => app(NumberReservationService::class)->expire($old->reservation->id),
            fn () => app(CheckoutService::class)->reserve(Customer::findOrFail($other->id), $offer->id, $quote, (string) Str::uuid()),
        ]);
        $this->assertSame(['accepted', 'accepted'], $results);
        $this->assertSame('expired', $old->fresh()->status);
        $new = CommerceOrder::where('customer_id', $other->id)->firstOrFail();
        $this->assertSame('reserved', $new->status);
        $this->assertSame($new->reservation->id, $number->fresh()->current_reservation_id);
        $this->assertFalse(app(NumberReservationService::class)->expire($old->reservation->id));
        $this->assertSame($new->reservation->id, $number->fresh()->current_reservation_id);
        $this->assertDatabaseCount('commerce_invoices', 2);
    }

    public function test_withdrawal_and_republication_cannot_accept_a_stale_quote(): void
    {
        [$offer, $number, $admin] = $this->offer();
        $buyer = $this->buyer();
        $quote = app(CheckoutService::class)->quote($buyer, $offer->id);
        $results = $this->race([
            fn () => app(CheckoutService::class)->reserve(Customer::findOrFail($buyer->id), $offer->id, $quote, (string) Str::uuid()),
            function () use ($offer, $number, $admin) {
                $actor = User::findOrFail($admin->id);
                $offers = app(NumberOfferService::class);
                $offers->withdraw($actor, $number->id, $number->inventory_revision, 'Synthetic repricing');
                $offers->publish($actor, $number->id, SipNumber::findOrFail($number->id)->inventory_revision, $offer->plan_version_id, 300000);
            },
        ]);
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $stock = $number->fresh();
        if ($stock->current_reservation_id === null) {
            $this->assertNotSame($offer->id, $stock->current_offer_id);
            $this->assertDatabaseCount('commerce_orders', 0);
            $this->assertSame(300000, $stock->currentOffer->monthly_amount);
        } else {
            $this->assertSame($offer->id, $stock->current_offer_id);
            $this->assertSame(250000, CommerceOrder::firstOrFail()->total_amount);
        }
    }
}
