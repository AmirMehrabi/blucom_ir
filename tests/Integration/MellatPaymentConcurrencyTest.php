<?php

namespace Tests\Integration;

use App\Models\CommerceOrder;
use App\Models\Customer;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Models\User;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\MellatPaymentService;
use App\Services\Commerce\NumberReservationService;
use App\Services\Commerce\PaymentGatewayService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\Support\MellatFixtures;
use Tests\TestCase;

/** Destructive fixtures require an explicitly named disposable MySQL database. */
class MellatPaymentConcurrencyTest extends TestCase
{
    use MellatFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('PAYMENT_CONCURRENCY_TESTS') !== '1' || config('database.default') !== 'mysql'
            || ! str_starts_with((string) config('database.connections.mysql.database'), 'blucom_payment_test')
            || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Opt-in disposable MySQL database and pcntl required.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
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

    public function test_competing_payment_keys_create_one_durable_bank_order(): void
    {
        [$order, $buyer] = $this->paymentFixture([function () {
            usleep(150000);

            return '0,SyntheticCaseRef';
        }], 0);
        $operation = fn () => app(MellatPaymentService::class)->initiate(Customer::findOrFail($buyer->id), $order->invoice->public_id, (string) Str::uuid());
        $this->assertSame(['accepted', 'accepted'], $this->race([$operation, $operation]));
        $this->assertDatabaseCount('payment_attempts', 1);
        $this->assertDatabaseCount('payment_events', 2);
        $this->assertSame('redirect_ready', PaymentAttempt::firstOrFail()->status);
        $this->assertSame(PaymentAttempt::firstOrFail()->id, $order->invoice->fresh()->current_payment_attempt_id);
    }

    public function test_duplicate_callbacks_have_one_financial_effect(): void
    {
        [$order, $buyer] = $this->paymentFixture(['0,SyntheticCaseRef', function () {
            usleep(150000);

            return '0';
        }, '0'], 0);
        $payments = app(MellatPaymentService::class);
        $attempt = $payments->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $callback = fn () => app(MellatPaymentService::class)->callback($attempt->public_id, $this->callbackPayload($attempt));
        $this->assertSame(['accepted', 'accepted'], $this->race([$callback, $callback]));
        $this->assertSame('settled', $attempt->fresh()->status);
        $this->assertSame($attempt->id, $order->invoice->fresh()->paid_payment_attempt_id);
        $this->assertSame(1, DB::table('payment_events')->where('type', 'settlement.confirmed')->count());
        $this->assertDatabaseCount('number_assignments', 1);
        $this->assertDatabaseCount('number_subscriptions', 1);
    }

    public function test_expiry_and_verification_preserve_paid_evidence_and_release_stock(): void
    {
        [$order, $buyer, $number] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0'], 0);
        $attempt = app(MellatPaymentService::class)->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $this->travel(16)->minutes();
        $this->assertSame(['accepted', 'accepted'], $this->race([
            fn () => app(NumberReservationService::class)->expire($order->reservation->id),
            fn () => app(MellatPaymentService::class)->callback($attempt->public_id, $this->callbackPayload($attempt)),
        ]));
        $this->assertSame('paid', $order->invoice->fresh()->status);
        $this->assertSame('paid_unfulfilled', $order->fresh()->status);
        $this->assertSame('expired', $order->reservation->fresh()->status);
        $this->assertNull($number->fresh()->current_reservation_id);
        $this->assertNull($number->fresh()->tenant_id);
    }

    public function test_new_customer_hold_survives_old_payment_and_expiry(): void
    {
        [$order, $buyer, $number] = $this->paymentFixture(['0,SyntheticCaseRef', '0', '0'], 0);
        $attempt = app(MellatPaymentService::class)->initiate($buyer, $order->invoice->public_id, (string) Str::uuid());
        $other = $this->buyer('002');
        $offer = $number->currentOffer;
        $quote = $order->item->snapshot;
        $this->travel(16)->minutes();
        $this->assertSame(['accepted', 'accepted', 'accepted'], $this->race([
            fn () => app(NumberReservationService::class)->expire($order->reservation->id),
            fn () => app(MellatPaymentService::class)->callback($attempt->public_id, $this->callbackPayload($attempt)),
            fn () => app(CheckoutService::class)->reserve(Customer::findOrFail($other->id), $offer->id, $quote, (string) Str::uuid()),
        ]));
        $new = CommerceOrder::query()->where('customer_id', $other->id)->firstOrFail();
        $this->assertSame('reserved', $new->status);
        $this->assertSame($new->reservation->id, $number->fresh()->current_reservation_id);
        $this->assertSame('paid_unfulfilled', $order->fresh()->status);
        $this->assertSame('paid', $order->invoice->fresh()->status);
        $this->assertDatabaseCount('number_assignments', 0);
    }

    public function test_disabling_gateway_serializes_against_new_payment_initiation(): void
    {
        [$order, $buyer, , $admin, , $gateway] = $this->paymentFixture(['0,SyntheticCaseRef'], 0);
        $results = $this->race([
            fn () => app(MellatPaymentService::class)->initiate(Customer::findOrFail($buyer->id), $order->invoice->public_id, (string) Str::uuid()),
            fn () => app(PaymentGatewayService::class)->update(User::findOrFail($admin->id), 'mellat', ['revision' => $gateway->revision, 'enabled' => false, 'amount_unit_confirmed' => true]),
        ]);
        $this->assertContains($results[0], ['accepted', 'rejected']);
        $this->assertSame('accepted', $results[1]);
        $this->assertFalse(PaymentGateway::findOrFail($gateway->id)->enabled);
        $this->assertDatabaseCount('payment_attempts', $results[0] === 'accepted' ? 1 : 0);
    }
}
