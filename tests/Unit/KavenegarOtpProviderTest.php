<?php

namespace Tests\Unit;

use App\Services\KavenegarOtpProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class KavenegarOtpProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.kavenegar.api_key' => 'test-key',
            'services.kavenegar.template' => 'otp-template',
            'services.kavenegar.timeout' => 5,
            'services.kavenegar.connect_timeout' => 3,
        ]);
    }

    public function test_sends_verify_lookup_request(): void
    {
        Http::fake([
            'api.kavenegar.com/*' => Http::response([
                'return' => ['status' => 200, 'message' => 'ok'],
                'entries' => [],
            ]),
        ]);

        app(KavenegarOtpProvider::class)->send('+98912123456', '123456');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), '/v1/test-key/verify/lookup.json')
                && $request['receptor'] === '+98912123456'
                && $request['token'] === '123456'
                && $request['template'] === 'otp-template';
        });
    }

    public function test_throws_when_connection_times_out(): void
    {
        Http::fake(function () {
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('OTP delivery failed (timeout).');

        app(KavenegarOtpProvider::class)->send('+98912123456', '123456');
    }

    public function test_throws_when_kavenegar_returns_error_status(): void
    {
        Http::fake([
            'api.kavenegar.com/*' => Http::response([
                'return' => ['status' => 201, 'message' => 'bad'],
            ]),
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kavenegar status 201');

        app(KavenegarOtpProvider::class)->send('+98912123456', '123456');
    }

    public function test_throws_when_api_key_missing(): void
    {
        config(['services.kavenegar.api_key' => '']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Kavenegar API key is not configured.');

        app(KavenegarOtpProvider::class)->send('+98912123456', '123456');
    }
}
