<?php

namespace App\Services\Commerce;

use App\Models\PaymentGateway;
use App\Models\PaymentGatewayVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentGatewayService
{
    public function __construct(private CommerceAudit $audit) {}

    public function update(User $actor, string $provider, #[\SensitiveParameter] array $data): void
    {
        abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
        abort_unless($provider === 'mellat', 404);
        validator($data, [
            'revision' => ['required', 'integer', 'min:0'], 'enabled' => ['required', 'boolean'],
            'merchant_terminal_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,17}$/D'],
            'merchant_username' => ['nullable', 'string', 'max:255'],
            'merchant_password' => ['nullable', 'string', 'max:500'],
            'amount_unit_confirmed' => ['required', 'boolean'],
        ])->validate();
        DB::transaction(function () use ($actor, $provider, $data) {
            $gateway = PaymentGateway::query()->where('provider', $provider)->lockForUpdate()->firstOrFail();
            if ($gateway->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['gateway' => 'تنظیمات تغییر کرده است؛ صفحه را تازه کنید.']);
            }
            $version = $gateway->currentVersion;
            $credentials = $version?->credentials ?? [];
            $before = $credentials;
            foreach (['merchant_terminal_id' => 'terminal_id', 'merchant_username' => 'username', 'merchant_password' => 'password'] as $field => $key) {
                if (($data[$field] ?? '') !== '' && ($data[$field] ?? null) !== null) {
                    $credentials[$key] = $data[$field];
                }
            }
            $complete = count(array_filter($credentials, fn ($value) => is_string($value) && $value !== '')) === 3;
            if ((bool) $data['enabled'] && (! $complete || ! $data['amount_unit_confirmed'])) {
                throw ValidationException::withMessages(['gateway' => 'برای فعال‌سازی، مشخصات پذیرنده و تأیید واحد ریال لازم است.']);
            }
            if ($credentials !== [] && ! $complete) {
                throw ValidationException::withMessages(['gateway' => 'هر سه مقدار پذیرنده را وارد کنید.']);
            }
            if ($complete && ($credentials !== $before || $version?->amount_unit_confirmed !== (bool) $data['amount_unit_confirmed'])) {
                $accountKey = $version !== null && ($before['terminal_id'] ?? null) === $credentials['terminal_id']
                    ? $version->account_key : (string) Str::uuid();
                $version = PaymentGatewayVersion::query()->create([
                    'payment_gateway_id' => $gateway->id, 'version' => ($version?->version ?? 0) + 1,
                    'account_key' => $accountKey, 'credentials' => $credentials, 'gateway_unit' => 'IRR',
                    'amount_unit_confirmed' => (bool) $data['amount_unit_confirmed'], 'created_by_user_id' => $actor->id,
                ]);
            }
            $gateway->update(['enabled' => (bool) $data['enabled'], 'current_version_id' => $version?->id, 'revision' => $gateway->revision + 1]);
            $this->audit->record($actor, 'payment_gateway.updated', 'payment_gateway', $gateway->id, 'Payment gateway settings updated', [
                'provider' => $provider, 'enabled' => $gateway->enabled, 'version_id' => $version?->id,
            ]);
        }, 3);
    }
}
