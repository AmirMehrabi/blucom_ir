<?php

namespace App\Services\Commerce;

use App\Models\PlanVersion;
use App\Models\SipNumber;
use App\Models\User;
use App\Services\NumberNormalizer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InventoryWorkspace
{
    public const STATUSES = ['all' => 'همه', 'attention' => 'نیازمند تکمیل', 'unpublished' => 'منتشرنشده', 'available' => 'آماده فروش', 'reserved' => 'رزروشده', 'assigned' => 'تخصیص‌یافته', 'hold' => 'متوقف‌شده'];

    public const ACTIONS = ['publish' => 'عرضه برای فروش', 'withdraw' => 'برداشتن از فروش', 'hold' => 'توقف عرضه', 'restore' => 'بازگشت به پیش‌نویس', 'price' => 'تنظیم قیمت', 'label' => 'تغییر برچسب'];

    public function __construct(private NumberReadinessService $readiness, private NumberInventoryService $inventory, private NumberOfferService $offers) {}

    public function query(array $filters): Builder
    {
        return SipNumber::query()->whereNotNull('inventory_state')
            ->with(['providerGateway', 'tenant', 'currentOffer', 'latestOffer'])
            ->withExists(['inboundRoute', 'outboundRoutes'])
            ->when(trim($filters['q'] ?? '') !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('normalized_number', 'like', '%'.trim($filters['q']).'%')
                ->orWhere('number', 'like', '%'.trim($filters['q']).'%')
                ->orWhere('label', 'like', '%'.trim($filters['q']).'%')))
            ->when($filters['gateway_id'] ?? null, fn ($q, $id) => $q->where('provider_gateway_id', $id))
            ->when($filters['tenant_id'] ?? null, fn ($q, $id) => $q->where('tenant_id', $id))
            ->when(isset($filters['min_price']) && $filters['min_price'] !== '', fn ($q) => $q->whereHas('currentOffer', fn ($q) => $q->whereRaw('COALESCE(annual_amount, monthly_amount) >= ?', [$filters['min_price']])))
            ->when(isset($filters['max_price']) && $filters['max_price'] !== '', fn ($q) => $q->whereHas('currentOffer', fn ($q) => $q->whereRaw('COALESCE(annual_amount, monthly_amount) <= ?', [$filters['max_price']])));
    }

    public function status(SipNumber $number): string
    {
        return match ($number->inventory_state) {
            'reserved' => 'reserved', 'assigned' => 'assigned', 'disabled', 'quarantined' => 'hold',
            default => $number->current_offer_id ? 'available' : 'unpublished',
        };
    }

    public function matches(SipNumber $number, string $status): bool
    {
        return $status === 'all' || ($status === 'attention'
            ? in_array($number->inventory_state, ['draft', 'available'], true) && $number->tenant_id === null && $this->readiness->issues($number, $number->providerGateway) !== []
            : $this->status($number) === $status);
    }

    public function sale(SipNumber $number): array
    {
        $offer = $number->currentOffer ?? $number->latestOffer;

        if ($number->currentOffer) {
            $offer = $number->currentOffer;
        } elseif ($number->inventory_sale_settings !== null) {
            return $number->inventory_sale_settings;
        }

        return $offer ? [
            'plan_version_id' => $offer->plan_version_id, 'amount' => $offer->annual_amount ?? $offer->monthly_amount,
            'billing_model' => $offer->billing_model,
        ] : [];
    }

    public function previewImport(array $lines): array
    {
        $seen = [];
        $rows = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cells = str_getcsv($line, ',', '"', '');
            $raw = trim($cells[0] ?? '');
            if (preg_match("/^'\\+[1-9][0-9]+$/D", $raw)) {
                $raw = substr($raw, 1);
            }
            if ($rows === [] && in_array(strtolower(ltrim($raw, "\xEF\xBB\xBF")), ['number', 'شماره'], true)) {
                continue;
            }
            $normalized = app(NumberNormalizer::class)->normalize($raw);
            $error = null;
            if ($normalized === null || ! preg_match('/^\+[1-9][0-9]{3,14}$/D', $normalized)) {
                $error = 'شماره معتبر نیست؛ این ردیف وارد نمی‌شود.';
            } elseif (isset($seen[$normalized])) {
                $error = 'تکراری در همین فهرست';
            } elseif (SipNumber::query()->where('normalized_number', $normalized)->exists()) {
                $error = 'قبلاً ثبت شده است';
            }
            $rows[] = ['number' => $raw, 'normalized' => $normalized, 'label' => mb_substr(trim($cells[1] ?? ''), 0, 255), 'error' => $error];
            if ($normalized !== null) {
                $seen[$normalized] = true;
            }
        }

        return $rows;
    }

    public function problem(SipNumber $number, int $revision, string $action, array $data): ?string
    {
        try {
            if ($number->inventory_revision !== $revision) {
                throw ValidationException::withMessages(['inventory' => 'شماره تغییر کرده است؛ دوباره انتخاب کنید.']);
            }
            if ($action === 'publish') {
                $this->inventory->editable($number, $revision);
                $issues = $this->readiness->issues($number, $number->providerGateway);
                if ($issues) {
                    return implode(' ', $issues);
                }
                $sale = $this->sale($number);
                if (($data['amount'] ?? null) !== null) {
                    $sale = $this->saleData($data);
                }
                $this->validateSale($sale);
            } elseif (in_array($action, ['withdraw', 'hold'], true) && $number->current_offer_id) {
                if ($number->tenant_id !== null || $number->inventory_state !== 'available' || $number->current_reservation_id || $number->current_assignment_id) {
                    return 'شماره رزرو یا تخصیص یافته است؛ عرضه آن قابل تغییر نیست.';
                }
            } else {
                $this->inventory->editable($number, $revision);
                if ($action === 'withdraw' || ($action === 'restore' && $number->inventory_state !== 'disabled') || ($action === 'hold' && $number->inventory_state === 'disabled')) {
                    return 'این اقدام برای وضعیت فعلی شماره قابل انجام نیست.';
                }
                if ($action === 'price') {
                    $this->validateSale($this->saleData($data));
                }
            }
        } catch (ValidationException $e) {
            return collect($e->errors())->flatten()->implode(' ');
        }

        return null;
    }

    public function saleData(array $data): array
    {
        return ['plan_version_id' => (int) ($data['plan_version_id'] ?? 0), 'amount' => (int) ($data['amount'] ?? 0),
            'billing_model' => config('commerce.separated_billing_enabled') ? 'annual_number' : 'legacy_monthly'];
    }

    public function validateSale(array $sale): void
    {
        $version = PlanVersion::query()->with('plan')->find($sale['plan_version_id'] ?? 0);
        if (! $version || ! $version->published_at || $version->plan->archived || ($sale['amount'] ?? 0) < 1
            || ($sale['amount'] ?? 0) > 1000000000000
            || ($sale['billing_model'] ?? '') !== (config('commerce.separated_billing_enabled') ? 'annual_number' : 'legacy_monthly')
            || (config('commerce.separated_billing_enabled') && ($version->monthly_price === null || ! isset($version->limits['numbers'])))) {
            throw ValidationException::withMessages(['sale' => 'قیمت و پلن معتبر برای شیوه پرداخت فعلی تعیین کنید.']);
        }
    }

    public function apply(User $actor, int $id, int $revision, string $action, array $data): void
    {
        // Each row is atomic. Existing domain services retain their locks, ownership checks and audit trail.
        DB::transaction(function () use ($actor, $id, $revision, $action, $data) {
            $number = SipNumber::query()->with(['providerGateway', 'currentOffer', 'latestOffer'])->findOrFail($id);
            if ($problem = $this->problem($number, $revision, $action, $data)) {
                throw ValidationException::withMessages(['inventory' => $problem]);
            }
            if ($action === 'publish') {
                $sale = isset($data['amount']) ? $this->saleData($data) : $this->sale($number);
                $this->offers->publish($actor, $id, $revision, $sale['plan_version_id'], $sale['amount']);
            } elseif ($action === 'withdraw') {
                $this->offers->withdraw($actor, $id, $revision, $data['reason']);
            } elseif ($action === 'hold') {
                if ($number->current_offer_id) {
                    $this->offers->withdraw($actor, $id, $revision, $data['reason']);
                    $revision++;
                }
                $this->inventory->change($actor, $id, $revision, 'disable', $data);
            } elseif ($action === 'restore') {
                $this->inventory->change($actor, $id, $revision, 'draft', $data);
            } else {
                $settings = $number->only(['label', 'provider_gateway_id', 'enabled', 'inbound_enabled', 'outbound_enabled', 'destination_prefixes']);
                $settings['inventory_sale_settings'] = $this->sale($number);
                if ($action === 'price') {
                    $settings['inventory_sale_settings'] = $this->saleData($data);
                } else {
                    $settings['label'] = $data['label'] ?? null;
                }
                $this->inventory->change($actor, $id, $revision, 'update', $settings);
            }
        });
    }
}
