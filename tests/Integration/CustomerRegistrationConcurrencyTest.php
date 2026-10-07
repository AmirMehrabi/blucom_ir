<?php

namespace Tests\Integration;

use App\Contracts\OtpProvider;
use App\Models\Customer;
use App\Models\CustomerRegistrationChallenge;
use App\Models\Tenant;
use App\Services\CustomerRegistrationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;

/** Run only against an explicitly enabled, disposable MySQL/MariaDB database. */
class CustomerRegistrationConcurrencyTest extends TestCase
{
    private OtpProvider $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('REGISTRATION_CONCURRENCY_TESTS') !== '1' || config('database.default') !== 'mysql'
            || ! str_starts_with((string) config('database.connections.mysql.database'), 'blucom_registration_test')
            || ! function_exists('pcntl_fork')) {
            $this->markTestSkipped('Opt-in disposable MySQL database and pcntl required.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertExitCode(0);
        $this->delivery = new class implements OtpProvider
        {
            public string $code = '';

            public function send(string $mobile, string $code): void
            {
                $this->code = $code;
            }
        };
        $this->app->instance(OtpProvider::class, $this->delivery);
    }

    private function race(\Closure $operation): array
    {
        DB::purge('mysql');
        $children = [];
        for ($i = 0; $i < 2; $i++) {
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
                } catch (HttpExceptionInterface $exception) {
                    $result = $exception->getStatusCode() === 429 ? 'rejected' : 'unexpected-http';
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
        sort($results);

        return $results;
    }

    public function test_simultaneous_verification_creates_one_customer_and_one_tenant(): void
    {
        $challenge = app(CustomerRegistrationService::class)->issue('Owner', '+989123456789', 'Business');
        $code = $this->delivery->code;
        $this->assertSame(['accepted', 'rejected'], $this->race(fn () => app(CustomerRegistrationService::class)->register($challenge->id, $challenge->mobile, $code)));
        $this->assertSame(1, Customer::query()->count());
        $this->assertSame(1, Tenant::query()->whereNull('system_key')->count());
        $this->assertSame(Customer::query()->sole()->id, Tenant::query()->whereNull('system_key')->sole()->owner_customer_id);
        $this->assertNotNull($challenge->fresh()->verified_at);
    }

    public function test_simultaneous_requests_issue_one_challenge_without_creating_accounts(): void
    {
        $this->assertSame(['accepted', 'rejected'], $this->race(fn () => app(CustomerRegistrationService::class)->issue('Owner', '+989123456789', 'Business')));
        $this->assertSame(1, CustomerRegistrationChallenge::query()->count());
        $this->assertSame(0, Customer::query()->count());
        $this->assertSame(0, Tenant::query()->whereNull('system_key')->count());
    }
}
