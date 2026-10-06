<?php

namespace App\Services\Commerce;

use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\User;
use App\Services\NumberNormalizer;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NumberInventoryService
{
    public function __construct(private NumberReadinessService $readiness, private CommerceAudit $audit) {}

    public function create(User $actor, array $data): SipNumber
    {
        $this->authorize($actor);
        try {
            return DB::transaction(function () use ($actor, $data) {
                $number = SipNumber::query()->create($this->settings($data) + [
                    'number' => $data['number'], 'normalized_number' => app(NumberNormalizer::class)->normalizeOrFail($data['number']),
                    'tenant_id' => null, 'requested_by_user_id' => null,
                    'status' => SipNumber::STATUS_AVAILABLE, 'inventory_state' => 'draft', 'inventory_revision' => 1,
                ]);
                $this->audit->record($actor, 'stock.created', 'sip_number', $number->id, 'Admin stock preparation');

                return $number;
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'normalized_number')) {
                throw ValidationException::withMessages(['number' => 'این شماره قبلاً ثبت شده است.']);
            }
            throw $e;
        }
    }

    public function change(User $actor, int $id, int $revision, string $action, array $data = []): SipNumber
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor, $id, $revision, $action, $data) {
            $number = SipNumber::query()->lockForUpdate()->findOrFail($id);
            $this->editable($number, $revision);
            if ($action === 'update') {
                $number->fill($this->settings($data));
                $number->fill(['reviewed_at' => null, 'reviewed_by_user_id' => null, 'readiness_evidence' => null, 'readiness_fingerprint' => null]);
            } elseif ($action === 'disable' && in_array($number->inventory_state, ['draft', 'available'], true)) {
                $number->inventory_state = 'disabled';
            } elseif ($action === 'draft' && $number->inventory_state === 'disabled') {
                $number->inventory_state = 'draft';
                $number->readiness_fingerprint = null;
            } else {
                throw ValidationException::withMessages(['inventory' => 'تغییر وضعیت مجاز نیست.']);
            }
            $number->inventory_revision++;
            $number->save();
            $this->audit->record($actor, 'stock.'.$action, 'sip_number', $number->id, $data['reason'] ?? 'Stock settings updated');

            return $number;
        });
    }

    public function review(User $actor, int $id, int $revision, string $evidence): void
    {
        $this->authorize($actor);
        // All operations locking both rows use gateway -> DID order.
        $gatewayId = SipNumber::query()->findOrFail($id)->provider_gateway_id;
        DB::transaction(function () use ($actor, $id, $revision, $evidence, $gatewayId) {
            $gateway = SipGateway::query()->lockForUpdate()->find($gatewayId);
            $number = SipNumber::query()->lockForUpdate()->findOrFail($id);
            $this->editable($number, $revision);
            $issues = $this->readiness->issues($number, $gateway, false);
            if ($issues !== []) {
                throw ValidationException::withMessages(['readiness' => $issues]);
            }
            $number->update([
                'reviewed_by_user_id' => $actor->id, 'reviewed_at' => now(),
                'readiness_evidence' => $evidence, 'readiness_fingerprint' => $this->readiness->fingerprint($number, $gateway),
                'inventory_revision' => $number->inventory_revision + 1,
            ]);
            $this->audit->record($actor, 'stock.reviewed', 'sip_number', $number->id, 'Technical review recorded');
        });
    }

    public function editable(SipNumber $number, int $revision): void
    {
        if ($number->inventory_state === null || $number->tenant_id !== null || $number->requested_by_user_id !== null
            || ! in_array($number->inventory_state, ['draft', 'available', 'disabled'], true)
            || $number->current_offer_id !== null || $number->inventory_revision !== $revision) {
            throw ValidationException::withMessages(['inventory' => 'شماره تغییر کرده یا قابل ویرایش نیست؛ ابتدا انتشار را بردارید و صفحه را تازه کنید.']);
        }
    }

    private function settings(array $data): array
    {
        return [
            'label' => $data['label'] ?? null, 'provider_gateway_id' => $data['provider_gateway_id'] ?? null,
            'enabled' => (bool) $data['enabled'], 'inbound_enabled' => (bool) $data['inbound_enabled'],
            'outbound_enabled' => (bool) $data['outbound_enabled'],
            'destination_prefixes' => array_values(array_unique($data['destination_prefixes'] ?? [])),
        ];
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
    }
}
