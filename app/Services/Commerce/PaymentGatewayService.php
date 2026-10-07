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
        abort_unless(in_array($provider, ['mellat', 'zibal'], true), 404);
        $rules = [
            'revision' => ['required', 'integer', 'min:0'], 'enabled' => ['required', 'boolean'],
            'amount_unit_confirmed' => ['required', 'boolean'],
        ];
        if ($provider === 'mellat') {
            $rules += [
                'merchant_terminal_id' => ['nullable', 'string', 'regex:/^[1-9][0-9]{0,17}$/D'],
                'merchant_username' => ['nullable', 'string', 'max:255'],
                'merchant_password' => ['nullable', 'string', 'max:500'],
            ];
        } else {
            $rules['merchant_id'] = ['nullable', 'string', 'max:255', 'regex:/^[A-Za-z0-9_-]+$/D'];
        }
        validator($data, $rules)->validate();
        DB::transaction(function () use ($actor, $provider, $data) {
            $gateway = PaymentGateway::query()->where('provider', $provider)->lockForUpdate()->firstOrFail();
            if ($gateway->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['gateway' => 'تنظیمات تغییر کرده است؛ صفحه را تازه کنید.']);
            }
            $version = $gateway->currentVersion;
            $credentials = $version?->credentials ?? [];
            $before = $credentials;
            $credentialFields = $provider === 'mellat'
                ? ['merchant_terminal_id' => 'terminal_id', 'merchant_username' => 'username', 'merchant_password' => 'password']
                : ['merchant_id' => 'merchant'];
            foreach ($credentialFields as $field => $key) {
                if (($data[$field] ?? '') !== '' && ($data[$field] ?? null) !== null) {
                    $credentials[$key] = $data[$field];
                }
            }
            $requiredKeys = $provider === 'mellat' ? ['terminal_id', 'username', 'password'] : ['merchant'];
            $complete = collect($requiredKeys)->every(fn ($key) => is_string($credentials[$key] ?? null) && $credentials[$key] !== '');
            if ((bool) $data['enabled'] && (! $complete || ! $data['amount_unit_confirmed'])) {
                throw ValidationException::withMessages(['gateway' => 'برای فعال‌سازی، مشخصات پذیرنده و تأیید واحد ریال لازم است.']);
            }
            if ($credentials !== [] && ! $complete) {
                throw ValidationException::withMessages(['gateway' => $provider === 'mellat'
                    ? 'هر سه مقدار پذیرنده را وارد کنید.' : 'شناسه پذیرنده را وارد کنید.']);
            }
            if ($complete && ($credentials !== $before || $version?->amount_unit_confirmed !== (bool) $data['amount_unit_confirmed'])) {
                $identityKey = $provider === 'mellat' ? 'terminal_id' : 'merchant';
                $accountKey = $version !== null && ($before[$identityKey] ?? null) === $credentials[$identityKey]
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

    public function activate(User $actor, string $provider): void
    {
        abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
        abort_unless(in_array($provider, ['mellat', 'zibal'], true), 404);
        DB::transaction(function () use ($actor, $provider): void {
            $gateways = PaymentGateway::query()->lockForUpdate()->get()->keyBy('provider');
            $gateway = $gateways->get($provider);
            if ($gateway === null || ! $gateway->enabled || $gateway->currentVersion === null
                || ! $gateway->currentVersion->amount_unit_confirmed || $gateway->currentVersion->gateway_unit !== 'IRR') {
                throw ValidationException::withMessages(['gateway' => 'برای انتخاب، درگاه باید پیکربندی و فعال باشد.']);
            }
            foreach ($gateways as $record) {
                $record->update(['active' => $record->provider === $provider]);
            }
            $this->audit->record($actor, 'payment_gateway.activated', 'payment_gateway', $gateway->id, 'Active payment provider changed', [
                'provider' => $provider,
            ]);
        }, 3);
    }
}
