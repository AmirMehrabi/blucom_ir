<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CustomerLineSetupService
{
    public function __construct(private readonly NumberNormalizer $numbers) {}

    /** @param array<string, mixed> $data */
    public function addProvider(Tenant $tenant, array $data): SipGateway
    {
        $credentials = $data['connection_method'] === 'credentials';

        $gateway = SipGateway::query()->create([
            'tenant_id' => $tenant->id,
            'name' => 'customer-'.$tenant->id.'-'.Str::lower(Str::random(12)),
            'display_name' => $data['display_name'],
            'provider_name' => $data['provider_name'],
            'connection_method' => $data['connection_method'],
            'verification_status' => SipGateway::STATUS_PENDING,
            'host' => $data['host'],
            'port' => $data['port'] ?? 5060,
            'transport' => $data['transport'] ?? 'udp',
            'username' => $credentials ? $data['username'] : null,
            'password_encrypted' => $credentials ? $data['password'] : null,
            'register' => $credentials,
            'profile' => 'external',
            'context' => 'public',
            'enabled' => false,
            'approved_for_outbound' => false,
        ]);

        Log::info('Customer provider submitted', ['tenant_id' => $tenant->id, 'gateway_id' => $gateway->id]);

        return $gateway;
    }

    /** @param array<string, mixed> $data */
    public function resubmitProvider(Tenant $tenant, SipGateway $gateway, array $data): void
    {
        if ($gateway->tenant_id !== $tenant->id
            || ! in_array($gateway->verification_status, [SipGateway::STATUS_PENDING, SipGateway::STATUS_REJECTED], true)) {
            abort(404);
        }

        DB::transaction(function () use ($gateway, $data): void {
            $credentials = $data['connection_method'] === 'credentials';
            $gateway->update([
                'display_name' => $data['display_name'],
                'provider_name' => $data['provider_name'],
                'connection_method' => $data['connection_method'],
                'host' => $data['host'],
                'port' => $data['port'] ?? 5060,
                'transport' => $data['transport'] ?? 'udp',
                'username' => $credentials ? $data['username'] : null,
                'password_encrypted' => $credentials ? $data['password'] : null,
                'register' => $credentials,
                'verification_status' => SipGateway::STATUS_PENDING,
                'enabled' => false,
                'approved_for_outbound' => false,
            ]);
            $gateway->sipNumbers()->where('status', SipNumber::STATUS_ASSIGNED)->update(['status' => SipNumber::STATUS_PENDING]);
        });

        Log::info('Customer provider resubmitted', ['tenant_id' => $tenant->id, 'gateway_id' => $gateway->id]);
    }

    public function addNumber(Tenant $tenant, SipGateway $gateway, string $rawNumber): SipNumber
    {
        if ($gateway->tenant_id !== $tenant->id) {
            throw ValidationException::withMessages(['gateway_id' => 'اتصال انتخاب‌شده متعلق به شما نیست.']);
        }

        $normalized = $this->numbers->normalizeOrFail($rawNumber);
        if (SipNumber::query()->where('normalized_number', $normalized)->exists()) {
            throw ValidationException::withMessages(['number' => 'این شماره قبلاً ثبت شده است.']);
        }

        $number = SipNumber::query()->create([
            'tenant_id' => $tenant->id,
            'number' => $rawNumber,
            'normalized_number' => $normalized,
            'provider_gateway_id' => $gateway->id,
            'status' => SipNumber::STATUS_PENDING,
            'enabled' => true,
            'inbound_enabled' => true,
            'outbound_enabled' => true,
        ]);

        Log::info('Customer number submitted', ['tenant_id' => $tenant->id, 'sip_number_id' => $number->id]);

        return $number;
    }

    public function resubmitNumber(Tenant $tenant, SipNumber $number, SipGateway $gateway, string $rawNumber): void
    {
        if ($number->tenant_id !== $tenant->id
            || ! in_array($number->status, [SipNumber::STATUS_PENDING, SipNumber::STATUS_DISABLED], true)
            || $number->providerGateway?->tenant_id !== $tenant->id
            || $gateway->tenant_id !== $tenant->id) {
            abort(404);
        }

        $normalized = $this->numbers->normalizeOrFail($rawNumber);
        if (SipNumber::query()->where('normalized_number', $normalized)->where('id', '!=', $number->id)->exists()) {
            throw ValidationException::withMessages(['number' => 'این شماره قبلاً ثبت شده است.']);
        }

        DB::transaction(function () use ($number, $gateway, $rawNumber, $normalized): void {
            $number->update([
                'number' => $rawNumber,
                'normalized_number' => $normalized,
                'provider_gateway_id' => $gateway->id,
                'status' => SipNumber::STATUS_PENDING,
                'enabled' => true,
            ]);
            $number->outboundRoutes()->update(['gateway_id' => $gateway->id]);
        });

        Log::info('Customer number resubmitted', ['tenant_id' => $tenant->id, 'sip_number_id' => $number->id]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{extension: ?SipExtension, password: ?string}
     */
    public function setAnswerer(Tenant $tenant, SipNumber $number, array $data): array
    {
        if ($number->tenant_id !== $tenant->id
            || ! in_array($number->status, [SipNumber::STATUS_PENDING, SipNumber::STATUS_ASSIGNED], true)
            || $number->providerGateway?->tenant_id !== $tenant->id
            || $number->providerGateway?->verification_status === SipGateway::STATUS_REJECTED) {
            abort(404);
        }

        return DB::transaction(function () use ($tenant, $number, $data): array {
            $scheduleSettings = $this->scheduleSettings($tenant, $number, $data);
            if ($data['answerer'] === 'menu') {
                $menu = IvrMenu::query()->whereBelongsTo($tenant)->where('enabled', true)
                    ->whereNotNull('published_config')->findOrFail($data['menu_id']);
                InboundRoute::query()->updateOrCreate(
                    ['sip_number_id' => $number->id],
                    ['tenant_id' => $tenant->id, 'destination_type' => 'ivr', 'destination_id' => $menu->id, 'enabled' => true] + $scheduleSettings,
                );
                Log::info('Customer call menu selected', ['tenant_id' => $tenant->id, 'sip_number_id' => $number->id, 'ivr_menu_id' => $menu->id]);

                return ['extension' => null, 'password' => null];
            }
            if ($data['answerer'] === 'team') {
                abort_unless(config('voip.queues_enabled'), 404);
                $queue = CallQueue::query()->whereBelongsTo($tenant)->where('enabled', true)
                    ->whereHas('members', fn ($query) => $query->where('enabled', true))
                    ->findOrFail($data['queue_id']);
                InboundRoute::query()->updateOrCreate(
                    ['sip_number_id' => $number->id],
                    ['tenant_id' => $tenant->id, 'destination_type' => 'queue', 'destination_id' => $queue->id, 'enabled' => true] + $scheduleSettings,
                );
                Log::info('Customer call team selected', ['tenant_id' => $tenant->id, 'sip_number_id' => $number->id, 'queue_id' => $queue->id]);

                return ['extension' => null, 'password' => null];
            }
            $password = null;
            if ($data['answerer'] === 'existing') {
                $extension = SipExtension::query()
                    ->whereBelongsTo($tenant)
                    ->where('enabled', true)
                    ->findOrFail($data['extension_id']);
            } else {
                ['extension' => $extension, 'password' => $password] = $this->createPhone($tenant, $data['display_name']);
            }

            InboundRoute::query()->updateOrCreate(
                ['sip_number_id' => $number->id],
                ['tenant_id' => $tenant->id, 'destination_type' => 'extension', 'destination_id' => $extension->id, 'enabled' => true] + $scheduleSettings,
            );

            if (($data['configure_outbound'] ?? true) && ! OutboundRoute::query()->where('sip_extension_id', $extension->id)->exists()) {
                OutboundRoute::query()->create([
                    'tenant_id' => $tenant->id,
                    'sip_extension_id' => $extension->id,
                    'sip_number_id' => $number->id,
                    'gateway_id' => $number->provider_gateway_id,
                    'enabled' => true,
                ]);
            }

            Log::info('Customer answerer changed', ['tenant_id' => $tenant->id, 'sip_number_id' => $number->id, 'extension_id' => $extension->id]);

            return ['extension' => $extension, 'password' => $password];
        });
    }

    /** @return array{extension: SipExtension, password: string} */
    public function createPhone(Tenant $tenant, string $displayName, ?SipNumber $number = null): array
    {
        if ($number !== null && ($number->tenant_id !== $tenant->id
            || $number->providerGateway?->tenant_id !== $tenant->id)) {
            throw ValidationException::withMessages(['number' => 'شماره انتخاب‌شده متعلق به شما نیست.']);
        }
        [$extension, $password] = DB::transaction(function () use ($tenant, $displayName, $number): array {
            $password = Str::random(20);
            $extension = SipExtension::query()->create([
                'tenant_id' => $tenant->id,
                'extension' => $this->nextExtension(),
                'password_encrypted' => $password,
                'display_name' => $displayName,
                'enabled' => true,
            ]);
            if ($number !== null) {
                OutboundRoute::query()->create([
                    'tenant_id' => $tenant->id,
                    'sip_extension_id' => $extension->id,
                    'sip_number_id' => $number->id,
                    'gateway_id' => $number->provider_gateway_id,
                    'enabled' => true,
                ]);
            }

            return [$extension, $password];
        });
        Log::info('Customer phone user created', ['tenant_id' => $tenant->id, 'extension_id' => $extension->id]);

        return ['extension' => $extension, 'password' => $password];
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    private function scheduleSettings(Tenant $tenant, SipNumber $number, array $data): array
    {
        return app(InboundRoutingService::class)->scheduleSettings($tenant, $number, $data);
    }

    private function nextExtension(): string
    {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $number = (string) random_int(2000, 9999);
            if (! SipExtension::query()->where('extension', $number)->exists()) {
                return $number;
            }
        }

        throw ValidationException::withMessages(['answerer' => 'در حال حاضر امکان ساخت تلفن جدید نیست.']);
    }
}
