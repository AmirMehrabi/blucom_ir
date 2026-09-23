<?php

namespace App\Services;

use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class SipNumberService
{
    public function __construct(
        private readonly NumberNormalizer $numbers,
        private readonly TenantService $tenants,
    ) {}

    /**
     * Admin creates catalog stock (status=available, no tenant).
     *
     * @param  array{number: string, provider_gateway_id?: int|null, inbound_enabled?: bool, outbound_enabled?: bool}  $data
     */
    public function createInventory(array $data): SipNumber
    {
        $normalized = $this->numbers->normalizeOrFail($data['number']);
        $gatewayId = $this->enabledGatewayId($data['provider_gateway_id'] ?? null);

        return $this->createUnique([
            'tenant_id' => null,
            'requested_by_user_id' => null,
            'number' => $data['number'],
            'normalized_number' => $normalized,
            'provider_gateway_id' => $gatewayId,
            'status' => SipNumber::STATUS_AVAILABLE,
            'inbound_enabled' => (bool) ($data['inbound_enabled'] ?? true),
            'outbound_enabled' => (bool) ($data['outbound_enabled'] ?? true),
        ]);
    }

    /**
     * Customer submits a BYOD request (status=pending until admin approves).
     *
     * @return array{number: SipNumber, duplicate: bool}
     */
    public function requestByod(User $user, string $rawNumber): array
    {
        $normalized = $this->numbers->normalizeOrFail($rawNumber);

        $existing = SipNumber::query()->where('normalized_number', $normalized)->first();

        if ($existing !== null) {
            return ['number' => $existing, 'duplicate' => true];
        }

        $number = $this->createUnique([
            'tenant_id' => null,
            'requested_by_user_id' => $user->id,
            'number' => $rawNumber,
            'normalized_number' => $normalized,
            'provider_gateway_id' => null,
            'status' => SipNumber::STATUS_PENDING,
            'inbound_enabled' => true,
            'outbound_enabled' => true,
        ]);

        return ['number' => $number, 'duplicate' => false];
    }

    /**
     * Customer claims an available number for their tenant.
     *
     * @throws \RuntimeException
     */
    public function assignToTenant(SipNumber $number, Tenant $tenant): void
    {
        if ($number->status !== SipNumber::STATUS_AVAILABLE || $number->tenant_id !== null) {
            throw new \RuntimeException('این شماره دیگر در دسترس نیست.');
        }

        $tenant->assertActive();

        DB::transaction(function () use ($number, $tenant): void {
            $number->update([
                'tenant_id' => $tenant->id,
                'requested_by_user_id' => null,
                'status' => SipNumber::STATUS_ASSIGNED,
            ]);
        });
    }

    /**
     * Customer returns an assigned number to the pool (routes are removed).
     */
    public function releaseFromTenant(SipNumber $number): void
    {
        if ($number->status !== SipNumber::STATUS_ASSIGNED || $number->tenant_id === null) {
            throw new \RuntimeException('فقط شماره تخصیص‌یافته قابل بازگشت است.');
        }

        DB::transaction(function () use ($number): void {
            $number->inboundRoute()->delete();
            $number->outboundRoute()->delete();

            $number->update([
                'tenant_id' => null,
                'status' => SipNumber::STATUS_AVAILABLE,
            ]);
        });
    }

    /**
     * Admin approves a BYOD request.
     *
     * @param  'available'|'assign'  $disposition
     */
    public function approveByod(SipNumber $number, string $disposition = 'assign'): void
    {
        if ($number->status !== SipNumber::STATUS_PENDING) {
            throw new \RuntimeException('فقط درخواست‌های در انتظار قابل تأیید هستند.');
        }

        if ($disposition === 'available') {
            $number->update([
                'tenant_id' => null,
                'requested_by_user_id' => null,
                'status' => SipNumber::STATUS_AVAILABLE,
            ]);

            return;
        }

        $requester = $number->requestedBy;

        if ($requester?->tenant_id === null) {
            $number->update([
                'tenant_id' => null,
                'requested_by_user_id' => null,
                'status' => SipNumber::STATUS_AVAILABLE,
            ]);

            return;
        }

        $tenant = Tenant::query()->findOrFail($requester->tenant_id);
        $tenant->assertActive();

        $number->update([
            'tenant_id' => $tenant->id,
            'requested_by_user_id' => null,
            'status' => SipNumber::STATUS_ASSIGNED,
        ]);
    }

    public function rejectByod(SipNumber $number): void
    {
        if ($number->status !== SipNumber::STATUS_PENDING) {
            throw new \RuntimeException('فقط درخواست‌های در انتظار قابل رد هستند.');
        }

        $number->delete();
    }

    /**
     * Admin edits trunk/status/assignment (never changes the number identity).
     *
     * @param  array{status?: string, provider_gateway_id?: int|null, tenant_id?: int|null}  $data
     */
    public function updateByAdmin(SipNumber $number, array $data): void
    {
        $payload = [];

        if (array_key_exists('status', $data)) {
            $allowed = [
                SipNumber::STATUS_AVAILABLE,
                SipNumber::STATUS_ASSIGNED,
                SipNumber::STATUS_DISABLED,
            ];

            if (! in_array($data['status'], $allowed, true)) {
                throw new \InvalidArgumentException('وضعیت نامعتبر است.');
            }

            $payload['status'] = $data['status'];
        }

        if (array_key_exists('provider_gateway_id', $data)) {
            $payload['provider_gateway_id'] = $this->enabledGatewayId($data['provider_gateway_id']);
        }

        if (array_key_exists('tenant_id', $data)) {
            $tenantId = $data['tenant_id'];

            if ($tenantId === null) {
                if ($number->status === SipNumber::STATUS_ASSIGNED) {
                    $this->releaseFromTenant($number);
                }

                return;
            }

            $tenant = Tenant::query()->findOrFail($tenantId);
            $tenant->assertActive();

            $payload['tenant_id'] = $tenant->id;
            $payload['status'] = $payload['status'] ?? SipNumber::STATUS_ASSIGNED;
            $payload['requested_by_user_id'] = null;
        }

        if (($payload['status'] ?? null) === SipNumber::STATUS_ASSIGNED
            && ! array_key_exists('tenant_id', $payload)
            && $number->tenant_id === null) {
            throw new \InvalidArgumentException('برای وضعیت تخصیص‌یافته، مشتری را انتخاب کنید.');
        }

        $number->update($payload);
    }

    /**
     * Customer updates routing flags on their own assigned number.
     *
     * @param  array{status?: string, inbound_enabled?: bool, outbound_enabled?: bool}  $data
     */
    public function updateRoutingFlags(SipNumber $number, array $data): void
    {
        if ($number->tenant_id === null) {
            throw new \RuntimeException('شماره در اختیار شما نیست.');
        }

        $payload = [];

        if (array_key_exists('inbound_enabled', $data)) {
            $payload['inbound_enabled'] = (bool) $data['inbound_enabled'];
        }

        if (array_key_exists('outbound_enabled', $data)) {
            $payload['outbound_enabled'] = (bool) $data['outbound_enabled'];
        }

        if (array_key_exists('status', $data)) {
            if (! in_array($data['status'], [SipNumber::STATUS_ASSIGNED, SipNumber::STATUS_DISABLED], true)) {
                throw new \InvalidArgumentException('وضعیت نامعتبر است.');
            }

            $payload['status'] = $data['status'];
        }

        $number->update($payload);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createUnique(array $attributes): SipNumber
    {
        try {
            return SipNumber::query()->create($attributes);
        } catch (QueryException $e) {
            if ((string) $e->getCode() === '23000' || str_contains($e->getMessage(), 'normalized_number')) {
                throw new \RuntimeException('این شماره قبلاً ثبت شده است.');
            }

            throw $e;
        }
    }

    private function enabledGatewayId(mixed $gatewayId): ?int
    {
        if ($gatewayId === null || $gatewayId === '') {
            return null;
        }

        $gateway = SipGateway::query()
            ->whereKey((int) $gatewayId)
            ->where('enabled', true)
            ->first();

        if ($gateway === null) {
            throw new \InvalidArgumentException('دروازه انتخاب‌شده معتبر نیست.');
        }

        return $gateway->id;
    }
}
