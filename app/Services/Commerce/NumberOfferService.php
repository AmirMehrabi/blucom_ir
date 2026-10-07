<?php

namespace App\Services\Commerce;

use App\Models\NumberOffer;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class NumberOfferService
{
    public function __construct(private NumberReadinessService $readiness, private CommerceAudit $audit) {}

    public function publish(User $actor, int $id, int $revision, int $versionId, int $amount): NumberOffer
    {
        abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
        if ($amount < 1 || $amount > 1000000000000) {
            throw ValidationException::withMessages(['monthly_amount' => 'قیمت باید عدد صحیح مثبت به تومان باشد.']);
        }
        $gatewayId = SipNumber::query()->findOrFail($id)->provider_gateway_id;
        $planId = PlanVersion::query()->findOrFail($versionId)->plan_id;

        return DB::transaction(function () use ($actor, $id, $revision, $versionId, $amount, $gatewayId, $planId) {
            $plan = Plan::query()->lockForUpdate()->findOrFail($planId);
            $version = PlanVersion::query()->lockForUpdate()->findOrFail($versionId);
            $gateway = SipGateway::query()->lockForUpdate()->find($gatewayId);
            $number = SipNumber::query()->lockForUpdate()->findOrFail($id);
            app(NumberInventoryService::class)->editable($number, $revision);
            $issues = $this->readiness->issues($number, $gateway);
            if ($plan->archived || $version->published_at === null) {
                $issues[] = 'نسخه پلن باید منتشرشده و پلن غیرآرشیوی باشد.';
            }
            if ($issues !== []) {
                throw ValidationException::withMessages(['publication' => $issues]);
            }
            $offer = NumberOffer::query()->create([
                'sip_number_id' => $id, 'plan_version_id' => $versionId, 'monthly_amount' => $amount,
                'currency' => 'IRT', 'published_at' => now(),
            ]);
            $number->update(['current_offer_id' => $offer->id, 'inventory_state' => 'available', 'inventory_revision' => $revision + 1]);
            $this->audit->record($actor, 'offer.published', 'number_offer', $offer->id, 'Monthly offer published', ['monthly_amount' => $amount, 'currency' => 'IRT']);

            return $offer;
        });
    }

    public function withdraw(User $actor, int $id, int $revision, string $reason): void
    {
        abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
        DB::transaction(function () use ($actor, $id, $revision, $reason) {
            $number = SipNumber::query()->lockForUpdate()->findOrFail($id);
            if ($number->inventory_state !== 'available' || $number->tenant_id !== null || $number->current_offer_id === null
                || $number->current_reservation_id !== null || $number->current_assignment_id !== null || $number->inventory_revision !== $revision) {
                throw ValidationException::withMessages(['inventory' => 'انتشار تغییر کرده یا قابل برداشت نیست؛ صفحه را تازه کنید.']);
            }
            $offer = NumberOffer::query()->lockForUpdate()->findOrFail($number->current_offer_id);
            $offer->update(['withdrawn_at' => now()]);
            $number->update(['current_offer_id' => null, 'inventory_state' => 'draft', 'inventory_revision' => $revision + 1]);
            $this->audit->record($actor, 'offer.withdrawn', 'number_offer', $offer->id, $reason);
        });
    }

    /** Gateway edits must hold this lock through save to serialize with publication. */
    public function changeGateway(int $id, callable $change): mixed
    {
        return DB::transaction(function () use ($id, $change) {
            $gateway = SipGateway::query()->lockForUpdate()->findOrFail($id);
            if ($gateway->sipNumbers()->whereNotNull('current_offer_id')->exists()) {
                throw ValidationException::withMessages(['gateway' => 'ابتدا انتشار تمام پیشنهادهای این اتصال را بردارید.']);
            }

            return $change($gateway);
        });
    }
}
