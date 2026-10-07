<?php

namespace Tests\Support;

use App\Contracts\MellatClient;
use App\Models\PaymentGatewayVersion;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

class FakeMellatClient implements MellatClient
{
    public array $calls = [];

    public function __construct(public array $responses, private int $transactionLevel = 1) {}

    public function call(#[\SensitiveParameter] PaymentGatewayVersion $account, string $method, array $fields): string
    {
        if (DB::transactionLevel() !== $this->transactionLevel) {
            throw new RuntimeException('Provider called inside a service transaction.');
        }
        $this->calls[] = ['version_id' => $account->id, 'method' => $method, 'fields' => $fields];
        if ($this->responses === []) {
            throw new RuntimeException('Unexpected provider call: '.$method);
        }
        $response = array_shift($this->responses);
        if ($response instanceof Throwable) {
            throw $response;
        }
        if ($response instanceof Closure) {
            return $response($account, $method, $fields);
        }

        return $response;
    }
}
