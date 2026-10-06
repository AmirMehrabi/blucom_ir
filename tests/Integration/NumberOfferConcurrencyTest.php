<?php

namespace Tests\Integration;

use App\Enums\UserType;
use App\Models\NumberOffer;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\User;
use App\Services\Commerce\NumberInventoryService;
use App\Services\Commerce\NumberOfferService;
use App\Services\Commerce\PlanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/** Run only against a disposable MySQL/MariaDB database, never the application database. */
class NumberOfferConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('COMMERCE_CONCURRENCY_TESTS') !== '1' || config('database.default') !== 'mysql'
            || ! str_starts_with((string) config('database.connections.mysql.database'), 'blucom_commerce_test')
            || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Opt-in disposable MySQL database and pcntl required.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
    }

    private function fixture(): array
    {
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $gateway = SipGateway::factory()->create(['verification_status' => 'approved', 'approved_for_outbound' => true, 'tenant_id' => null]);
        $number = app(NumberInventoryService::class)->create($admin, [
            'number' => '+982155509876', 'provider_gateway_id' => $gateway->id,
            'enabled' => true, 'inbound_enabled' => true, 'outbound_enabled' => true, 'destination_prefixes' => ['+989'],
        ]);
        app(NumberInventoryService::class)->review($admin, $number->id, 1, 'Synthetic review for concurrency test');
        $plan = app(PlanService::class)->create($admin, 'Concurrent plan');
        $version = app(PlanService::class)->version($admin, $plan->id, ['extensions' => 5, 'queues' => 2, 'ivr_menus' => 2]);
        app(PlanService::class)->publish($admin, $version->id);

        return [$admin->id, $number->id, $number->fresh()->inventory_revision, $version->id, $gateway->id];
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

    public function test_competing_publications_create_only_one_current_offer(): void
    {
        [$actor, $number, $revision, $version] = $this->fixture();
        $publish = fn () => app(NumberOfferService::class)->publish(User::query()->findOrFail($actor), $number, $revision, $version, 250000);
        $results = $this->race([$publish, $publish]);
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $this->assertSame(1, NumberOffer::query()->count());
        $this->assertSame(NumberOffer::query()->firstOrFail()->id, SipNumber::query()->findOrFail($number)->current_offer_id);
    }

    public function test_gateway_disable_and_publication_serialize_without_publishing_disabled_stock(): void
    {
        [$actor, $number, $revision, $version, $gateway] = $this->fixture();
        $results = $this->race([
            fn () => app(NumberOfferService::class)->publish(User::query()->findOrFail($actor), $number, $revision, $version, 250000),
            fn () => app(NumberOfferService::class)->changeGateway($gateway, fn ($record) => $record->update(['enabled' => false])),
        ]);
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $stock = SipNumber::query()->findOrFail($number);
        if ($stock->current_offer_id !== null) {
            $this->assertTrue(SipGateway::query()->findOrFail($gateway)->enabled);
        } else {
            $this->assertFalse(SipGateway::query()->findOrFail($gateway)->enabled);
            $this->assertSame(0, NumberOffer::query()->count());
        }
    }

    public function test_competing_withdrawals_preserve_history_once(): void
    {
        [$actor, $number, $revision, $version] = $this->fixture();
        app(NumberOfferService::class)->publish(User::query()->findOrFail($actor), $number, $revision, $version, 250000);
        $revision = SipNumber::query()->findOrFail($number)->inventory_revision;
        $withdraw = fn () => app(NumberOfferService::class)->withdraw(User::query()->findOrFail($actor), $number, $revision, 'Concurrent withdrawal');
        $results = $this->race([$withdraw, $withdraw]);
        sort($results);
        $this->assertSame(['accepted', 'rejected'], $results);
        $this->assertNull(SipNumber::query()->findOrFail($number)->current_offer_id);
        $this->assertSame(1, NumberOffer::query()->count());
        $this->assertNotNull(NumberOffer::query()->firstOrFail()->withdrawn_at);
    }
}
