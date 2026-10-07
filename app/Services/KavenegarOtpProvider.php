<?php

namespace App\Services;

use App\Contracts\OtpProvider;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class KavenegarOtpProvider implements OtpProvider
{
    public function send(string $mobile, string $code): void
    {
        $key = (string) config('services.kavenegar.api_key');
        $template = (string) config('services.kavenegar.template');
        $timeout = (int) config('services.kavenegar.timeout', 5);
        $connectTimeout = (int) config('services.kavenegar.connect_timeout', 3);

        if ($key === '') {
            throw new RuntimeException('Kavenegar API key is not configured.');
        }

        try {
            $response = Http::withOptions([
                'connect_timeout' => $connectTimeout,
                'timeout' => $timeout,
            ])
                ->acceptJson()
                ->asForm()
                ->post(sprintf('https://api.kavenegar.com/v1/%s/verify/lookup.json', $key), [
                    'receptor' => $mobile,
                    'token' => $code,
                    'template' => $template,
                ]);
        } catch (ConnectionException $exception) {
            Log::warning('OTP provider connection failed', ['exception_class' => $exception::class]);
            throw new RuntimeException('OTP delivery failed (timeout).');
        } catch (\Throwable $exception) {
            Log::warning('OTP provider request failed', ['exception_class' => $exception::class]);
            $message = str_contains($exception->getMessage(), 'timed out')
                || str_contains($exception->getMessage(), 'timeout')
                || str_contains($exception->getMessage(), 'cURL error 28')
                ? 'OTP delivery failed (timeout).'
                : 'OTP delivery failed.';

            throw new RuntimeException($message);
        }

        if ($response->failed()) {
            $exception = new RuntimeException('OTP delivery failed (HTTP '.$response->status().').');
            report($exception);
            throw $exception;
        }

        $body = $response->json();
        $status = $body['return']['status'] ?? null;

        if ($status !== null && (int) $status !== 200) {
            $exception = new RuntimeException('OTP delivery failed (Kavenegar status '.$status.').');
            report($exception);
            throw $exception;
        }
    }
}
