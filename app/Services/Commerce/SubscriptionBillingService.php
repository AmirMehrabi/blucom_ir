<?php

namespace App\Services\Commerce;

use App\Models\CallQueue;
use App\Models\CommerceInvoice;
use App\Models\CommerceInvoiceItem;
use App\Models\Customer;
use App\Models\IvrMenu;
use App\Models\NumberReservation;
use App\Models\NumberTerm;
use App\Models\PaymentAttempt;
use App\Models\PlanVersion;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Support\Permissions;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubscriptionBillingService
{
    public function __construct(private CommerceAudit $audit) {}

    public function issueDue(): int
    {
        $count = 0;
        TenantSubscription::query()->where('status', 'active')->where('period_ends_at', '<=', now()->addDays(7))
            ->orderBy('id')->chunkById(100, function ($subscriptions) use (&$count): void {
                foreach ($subscriptions as $subscription) {
                    if ($this->issuePlan($subscription->id)) {
                        $count++;
                    }
                }
            });
        NumberTerm::query()->where('billing_model', 'annual_number')->where('status', 'active')->where('period_ends_at', '<=', now()->addDays(30))
            ->orderBy('id')->chunkById(100, function ($terms) use (&$count): void {
                foreach ($terms as $term) {
                    if ($this->issueNumber($term->id)) {
                        $count++;
                    }
                }
            });

        return $count;
    }

    public function scheduleNumberPrice(User $actor, int $numberId, int $annualAmount): void
    {
        validator(['annual_amount' => $annualAmount], ['annual_amount' => ['required', 'integer', 'min:1', 'max:1000000000000']])->validate();
        $initial = SipNumber::query()->findOrFail($numberId);
        DB::transaction(function () use ($actor, $numberId, $annualAmount, $initial) {
            $actor = User::query()->findOrFail($actor->id);
            abort_unless($actor->isAdmin() && ! $actor->isDisabled(), 403);
            Tenant::query()->lockForUpdate()->findOrFail($initial->tenant_id);
            $number = SipNumber::query()->lockForUpdate()->findOrFail($numberId);
            $term = NumberTerm::query()->where('number_assignment_id', $number->current_assignment_id)
                ->lockForUpdate()->firstOrFail();
            if ($number->inventory_state !== 'assigned' || $number->tenant_id !== $term->tenant_id
                || $term->billing_model !== 'annual_number' || $term->status !== 'active' || $annualAmount <= $term->annual_amount) {
                throw ValidationException::withMessages(['annual_amount' => 'فقط افزایش قیمت شماره تخصیص‌یافته با اطلاع‌رسانی قبلی قابل ثبت است.']);
            }
            $term->update(['pending_annual_amount' => $annualAmount,
                'pending_price_effective_at' => null, 'price_notice_sent_at' => null]);
            $this->audit->record($actor, 'number.price_scheduled', 'number_term', $term->id,
                'Annual number price increase awaiting customer notice', ['annual_amount' => $annualAmount]);
        }, 3);
    }

    public function issuePlan(int $id): ?CommerceInvoice
    {
        $initial = TenantSubscription::query()->findOrFail($id);

        return DB::transaction(function () use ($id, $initial) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($initial->tenant_id);
            $subscription = TenantSubscription::query()->lockForUpdate()->findOrFail($id);
            if ($subscription->status !== 'active' || ! $subscription->acceptsRenewal() || ! $subscription->period_ends_at
                || $subscription->period_ends_at->gt(now()->addDays(7))) {
                return null;
            }
            $start = $subscription->period_ends_at;
            $existing = CommerceInvoice::query()->where('tenant_subscription_id', $id)->where('kind', 'plan_renewal')
                ->where('period_starts_at', $start)->first();
            if ($existing) {
                if ($existing->status === 'void') {
                    if (PaymentAttempt::query()->where('commerce_invoice_id', $existing->id)
                        ->whereNotIn('status', ['initiation_failed', 'reversed'])->exists()) {
                        throw ValidationException::withMessages(['billing' => 'پرداخت قبلی نیاز به بررسی پشتیبانی دارد.']);
                    }
                    $existing->update(['status' => 'issued']);
                }

                return $existing;
            }
            $pendingReady = $subscription->pending_plan_version_id
                && ($subscription->pending_change_source !== 'price_notice'
                    || ($subscription->price_notice_sent_at !== null && $subscription->pending_effective_at?->lte($start)));
            $version = $pendingReady
                ? PlanVersion::query()->findOrFail($subscription->pending_plan_version_id)
                : $subscription->planVersion;
            if ($pendingReady && ! $this->fits($tenant, $version)) {
                $subscription->update(['pending_plan_version_id' => null, 'pending_change_source' => null,
                    'pending_effective_at' => null, 'revision' => $subscription->revision + 1]);
                $version = $subscription->planVersion;
            }
            $amount = $version->id === $subscription->plan_version_id ? $subscription->monthly_amount : $version->monthly_price;
            if (! is_int($amount) || $amount < 1 || $amount > 1000000000000) {
                throw ValidationException::withMessages(['billing' => 'قیمت پلن برای تمدید معتبر نیست.']);
            }
            $end = $this->nextMonth($start, $subscription->anchor_day ?: $start->day);

            return $this->invoice($tenant, 'plan_renewal', $amount, $start, $end, $start,
                ['tenant_subscription_id' => $id, 'plan_version_id' => $version->id],
                [['kind' => 'monthly_plan', 'amount' => $amount, 'snapshot' => ['plan_version_id' => $version->id, 'plan_name' => $version->plan->name]]]);
        }, 3);
    }

    public function issueNumber(int $id): ?CommerceInvoice
    {
        $initial = NumberTerm::query()->findOrFail($id);

        return DB::transaction(function () use ($id, $initial) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($initial->tenant_id);
            $term = NumberTerm::query()->lockForUpdate()->findOrFail($id);
            $assignment = $term->assignment;
            if ($term->billing_model !== 'annual_number' || $term->status !== 'active' || ! $term->period_ends_at || $term->period_ends_at->gt(now()->addDays(30))
                || ! $assignment || $assignment->released_at !== null) {
                return null;
            }
            $start = $term->period_ends_at;
            $existing = CommerceInvoice::query()->where('number_term_id', $id)->where('kind', 'number_renewal')
                ->where('period_starts_at', $start)->first();
            if ($existing) {
                return $existing;
            }
            $end = $this->nextYear($start, $term->anchor_month ?: $start->month, $term->anchor_day ?: $start->day);
            $amount = $term->pending_annual_amount && $term->price_notice_sent_at !== null
                && $term->pending_price_effective_at?->lte($start)
                ? $term->pending_annual_amount : $term->annual_amount;

            return $this->invoice($tenant, 'number_renewal', $amount, $start, $end, $start,
                ['number_term_id' => $id],
                [['kind' => 'annual_number', 'amount' => $amount,
                    'snapshot' => ['number_assignment_id' => $assignment->id, 'number' => $assignment->sipNumber?->normalized_number]]]);
        }, 3);
    }

    public function requestChange(Customer $actor, int $versionId): ?CommerceInvoice
    {
        return DB::transaction(function () use ($actor, $versionId) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($actor->tenant_id);
            $actor = Customer::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->canAccessTenant($tenant) && $actor->hasPermission(Permissions::BILLING_MANAGE), 403);
            $subscription = TenantSubscription::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $version = PlanVersion::query()->with('plan')->findOrFail($versionId);
            if ($subscription->status !== 'active' || ! $subscription->grace_ends_at?->isFuture()
                || ! $version->published_at || $version->published_at->isFuture() || $version->plan->archived
                || $version->monthly_price < 1 || $version->monthly_price > 1000000000000
                || ! isset($version->limits['numbers'], $version->limits['extensions'],
                    $version->limits['queues'], $version->limits['ivr_menus'])) {
                throw ValidationException::withMessages(['plan' => 'تغییر پلن در حال حاضر امکان‌پذیر نیست.']);
            }
            if (CommerceInvoice::query()->where('tenant_subscription_id', $subscription->id)
                ->where('kind', 'plan_renewal')->where('status', 'issued')->exists()) {
                throw ValidationException::withMessages(['plan' => 'ابتدا صورتحساب تمدید ماهانه را پرداخت کنید.']);
            }
            $oldUpgrade = CommerceInvoice::query()->where('tenant_subscription_id', $subscription->id)
                ->where('kind', 'plan_upgrade')->where('status', 'issued')->lockForUpdate()->first();
            if ($oldUpgrade) {
                if (PaymentAttempt::query()->where('commerce_invoice_id', $oldUpgrade->id)
                    ->whereNotIn('status', ['initiation_failed', 'reversed'])->exists()) {
                    throw ValidationException::withMessages(['plan' => 'پرداخت ارتقای قبلی در جریان است؛ پس از تعیین نتیجه دوباره اقدام کنید.']);
                }
                $oldUpgrade->update(['status' => 'void']);
            }
            if ($version->id === $subscription->plan_version_id) {
                if ($subscription->pending_change_source === 'price_notice') {
                    throw ValidationException::withMessages(['plan' => 'تغییر قیمت اعلام‌شده با انتخاب دوباره همین نسخه لغو نمی‌شود.']);
                }
                $subscription->update(['pending_plan_version_id' => null, 'pending_change_source' => null,
                    'pending_effective_at' => null, 'revision' => $subscription->revision + 1]);

                return null;
            }
            if ($version->monthly_price < $subscription->monthly_amount) {
                $overages = $this->overages($tenant, $version);
                if ($overages !== []) {
                    $labels = ['numbers' => 'شماره', 'extensions' => 'داخلی', 'queues' => 'تیم', 'ivr_menus' => 'منوی تماس'];
                    $details = collect($overages)->map(fn ($usage, $key) => $labels[$key].' '.$usage['used'].' از '.$usage['limit'])->implode('، ');
                    throw ValidationException::withMessages(['plan' => 'برای کاهش پلن، ابتدا ظرفیت‌های اضافی را آزاد یا غیرفعال کنید: '.$details.'.']);
                }
                $subscription->update(['pending_plan_version_id' => $version->id, 'pending_change_source' => 'customer',
                    'pending_effective_at' => $subscription->period_ends_at, 'revision' => $subscription->revision + 1]);
                $this->audit->customer($actor, 'plan.downgrade_scheduled', 'tenant_subscription', $subscription->id, ['plan_version_id' => $version->id]);

                return null;
            }
            if ($version->monthly_price <= $subscription->monthly_amount) {
                throw ValidationException::withMessages(['plan' => 'پلن انتخاب‌شده ارتقای قیمت نیست.']);
            }
            $seconds = max(0, now()->diffInSeconds($subscription->period_ends_at, false));
            $total = max(1, $subscription->period_starts_at->diffInSeconds($subscription->period_ends_at));
            $amount = max(1, (int) ceil(($version->monthly_price - $subscription->monthly_amount) * $seconds / $total));
            $invoice = $this->invoice($tenant, 'plan_upgrade', $amount, now(), $subscription->period_ends_at, now(),
                ['tenant_subscription_id' => $subscription->id, 'plan_version_id' => $version->id],
                [['kind' => 'plan_upgrade', 'amount' => $amount, 'snapshot' => ['from_version_id' => $subscription->plan_version_id,
                    'to_version_id' => $version->id, 'next_monthly_amount' => $version->monthly_price]]]);
            $this->audit->customer($actor, 'plan.upgrade_quoted', 'commerce_invoice', $invoice->id, ['plan_version_id' => $version->id]);

            return $invoice;
        }, 3);
    }

    public function cancelPlan(Customer $actor, bool $undo = false): void
    {
        DB::transaction(function () use ($actor, $undo) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($actor->tenant_id);
            $actor = Customer::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->canAccessTenant($tenant) && $actor->hasPermission(Permissions::BILLING_MANAGE), 403);
            $subscription = TenantSubscription::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            if ($subscription->status !== 'active' || ! $subscription->period_ends_at?->isFuture()
                || ($undo && $subscription->cancel_at === null)) {
                throw ValidationException::withMessages(['billing' => 'لغو یا بازگرداندن اشتراک اکنون امکان‌پذیر نیست.']);
            }
            if (! $undo) {
                $open = CommerceInvoice::query()->where('tenant_subscription_id', $subscription->id)
                    ->where('kind', 'plan_renewal')->where('status', 'issued')->lockForUpdate()->get();
                foreach ($open as $invoice) {
                    if (PaymentAttempt::query()->where('commerce_invoice_id', $invoice->id)
                        ->whereNotIn('status', ['initiation_failed', 'reversed'])->exists()) {
                        throw ValidationException::withMessages(['billing' => 'پرداخت تمدید در جریان است؛ پس از تعیین نتیجه دوباره لغو کنید.']);
                    }
                    $invoice->update(['status' => 'void']);
                }
            }
            $subscription->update(['cancel_at' => $undo ? null : $subscription->period_ends_at, 'reactivation_requested_at' => null,
                'pending_plan_version_id' => null, 'pending_change_source' => null,
                'pending_effective_at' => null, 'revision' => $subscription->revision + 1]);
            $this->audit->customer($actor, $undo ? 'plan.cancellation_undone' : 'plan.cancellation_scheduled',
                'tenant_subscription', $subscription->id, []);
        }, 3);
    }

    public function requestReactivation(Customer $actor): CommerceInvoice
    {
        return DB::transaction(function () use ($actor) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($actor->tenant_id);
            $actor = Customer::query()->lockForUpdate()->findOrFail($actor->id);
            abort_unless($actor->canAccessTenant($tenant) && $actor->hasPermission(Permissions::BILLING_MANAGE), 403);
            $subscription = TenantSubscription::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            if ($subscription->cancel_at === null || $subscription->cancel_at->isFuture()) {
                throw ValidationException::withMessages(['billing' => 'اشتراک در وضعیت قابل بازفعال‌سازی نیست.']);
            }
            $subscription->update(['reactivation_requested_at' => now(), 'revision' => $subscription->revision + 1]);

            return $this->issuePlan($subscription->id)
                ?? throw ValidationException::withMessages(['billing' => 'صورتحساب بازفعال‌سازی صادر نشد.']);
        }, 3);
    }

    public function applyPaid(int $invoiceId): void
    {
        $initial = CommerceInvoice::query()->findOrFail($invoiceId);
        DB::transaction(function () use ($initial) {
            Tenant::query()->lockForUpdate()->findOrFail($initial->tenant_id);
            $invoice = CommerceInvoice::query()->lockForUpdate()->findOrFail($initial->id);
            if ($invoice->applied_at !== null) {
                return;
            }
            if (! $invoice->hasValidSettlement() || $invoice->items()->sum('amount') !== $invoice->total_amount) {
                throw ValidationException::withMessages(['billing' => 'پرداخت معتبر و تسویه‌شده برای تمدید موجود نیست.']);
            }
            $now = now();
            if ($invoice->kind === 'number_renewal') {
                $term = NumberTerm::query()->lockForUpdate()->findOrFail($invoice->number_term_id);
                $assignment = $term->assignment;
                if (! $assignment || $assignment->released_at || $term->tenant_id !== $invoice->tenant_id
                    || ! $term->period_ends_at?->equalTo($invoice->period_starts_at)) {
                    throw ValidationException::withMessages(['billing' => 'مالکیت یا دوره شماره تغییر کرده است؛ پرداخت نیاز به بررسی دارد.']);
                }
                $start = $now->gt($invoice->grace_ends_at) ? $now : $term->period_ends_at;
                $anchorMonth = $now->gt($invoice->grace_ends_at) ? $now->month : ($term->anchor_month ?: $start->month);
                $anchorDay = $now->gt($invoice->grace_ends_at) ? $now->day : ($term->anchor_day ?: $start->day);
                $end = $this->nextYear($start, $anchorMonth, $anchorDay);
                $appliedPrice = $term->pending_annual_amount !== null
                    && $invoice->total_amount === $term->pending_annual_amount
                    && $term->price_notice_sent_at !== null
                    && $term->pending_price_effective_at?->lte($invoice->period_starts_at);
                $term->update(['status' => 'active', 'period_starts_at' => $start, 'period_ends_at' => $end,
                    'grace_ends_at' => $end->copy()->addDays(7), 'release_review_at' => $end->copy()->addDays(37),
                    'anchor_month' => $anchorMonth, 'anchor_day' => $anchorDay,
                    'annual_amount' => $invoice->total_amount,
                    'pending_annual_amount' => $appliedPrice ? null : $term->pending_annual_amount,
                    'pending_price_effective_at' => $appliedPrice ? null : $term->pending_price_effective_at]);
            } else {
                $subscription = TenantSubscription::query()->lockForUpdate()->findOrFail($invoice->tenant_subscription_id);
                if ($subscription->tenant_id !== $invoice->tenant_id) {
                    throw ValidationException::withMessages(['billing' => 'مالکیت اشتراک تغییر کرده است.']);
                }
                $version = PlanVersion::query()->findOrFail($invoice->plan_version_id);
                if ($invoice->kind === 'plan_upgrade') {
                    if ($subscription->plan_version_id !== ($invoice->item?->snapshot['from_version_id'] ?? null)
                        || $subscription->period_ends_at?->lt($now)) {
                        throw ValidationException::withMessages(['billing' => 'دوره پلن برای ارتقا تغییر کرده است؛ پرداخت نیاز به بررسی دارد.']);
                    }
                    $start = $now;
                    $end = $subscription->period_ends_at;
                    $subscription->update(['plan_version_id' => $version->id, 'monthly_amount' => $version->monthly_price,
                        'pending_plan_version_id' => null, 'pending_change_source' => null,
                        'pending_effective_at' => null, 'revision' => $subscription->revision + 1]);
                } elseif ($invoice->kind === 'plan_renewal') {
                    if (! $subscription->period_ends_at?->equalTo($invoice->period_starts_at)
                        || ! $subscription->acceptsRenewal()
                        || ($version->id !== $subscription->plan_version_id && ! $this->fits($subscription->tenant, $version))) {
                        throw ValidationException::withMessages(['billing' => 'دوره یا ظرفیت پلن تغییر کرده است؛ پرداخت نیاز به بررسی دارد.']);
                    }
                    $late = $subscription->reactivation_requested_at !== null || $now->gt($invoice->grace_ends_at);
                    $start = $late ? $now : $subscription->period_ends_at;
                    $anchor = $late ? $now->day : ($subscription->anchor_day ?: $start->day);
                    $end = $this->nextMonth($start, $anchor);
                    $appliedPending = $version->id === $subscription->pending_plan_version_id;
                    $subscription->update(['status' => 'active', 'cancel_at' => null, 'reactivation_requested_at' => null, 'plan_version_id' => $version->id,
                        'monthly_amount' => $invoice->total_amount,
                        'period_starts_at' => $start, 'period_ends_at' => $end, 'grace_ends_at' => $end->copy()->addDays(7),
                        'pending_plan_version_id' => $appliedPending ? null : $subscription->pending_plan_version_id,
                        'pending_change_source' => $appliedPending ? null : $subscription->pending_change_source,
                        'pending_effective_at' => $appliedPending ? null : $subscription->pending_effective_at,
                        'anchor_day' => $anchor, 'revision' => $subscription->revision + 1]);
                } else {
                    throw ValidationException::withMessages(['billing' => 'نوع صورتحساب معتبر نیست.']);
                }
            }
            $invoice->update(['applied_at' => $now, 'applied_period_starts_at' => $start, 'applied_period_ends_at' => $end]);
            $this->audit->system('billing.invoice_applied', 'commerce_invoice', $invoice->id, ['kind' => $invoice->kind], 'Verified settled payment applied');
        }, 3);
    }

    public function fits(Tenant $tenant, PlanVersion $version): bool
    {
        return $this->overages($tenant, $version) === [];
    }

    public function overages(Tenant $tenant, PlanVersion $version): array
    {
        $limits = $version->limits;
        if (! isset($limits['numbers'], $limits['extensions'], $limits['queues'], $limits['ivr_menus'])) {
            return ['plan' => ['used' => 0, 'limit' => 0]];
        }
        $counts = [
            'numbers' => SipNumber::query()->where('tenant_id', $tenant->id)->where('inventory_state', 'assigned')->count()
                + NumberReservation::query()->where('tenant_id', $tenant->id)->where('status', 'held')->where('expires_at', '>', now())->count(),
            'extensions' => SipExtension::query()->where('tenant_id', $tenant->id)->where('enabled', true)->count(),
            'queues' => CallQueue::query()->where('tenant_id', $tenant->id)->where('enabled', true)->count(),
            'ivr_menus' => IvrMenu::query()->where('tenant_id', $tenant->id)->where('enabled', true)->count(),
        ];

        return collect($counts)->filter(fn ($count, $key) => $count > (int) $limits[$key])
            ->map(fn ($count, $key) => ['used' => $count, 'limit' => (int) $limits[$key]])->all();
    }

    private function invoice(Tenant $tenant, string $kind, int $amount, CarbonInterface $start, CarbonInterface $end, CarbonInterface $due, array $subject, array $items): CommerceInvoice
    {
        $owner = Customer::query()->findOrFail($tenant->owner_customer_id);
        $id = (string) Str::uuid();
        $invoice = CommerceInvoice::query()->create([
            'public_id' => $id, 'invoice_number' => 'INV-'.strtoupper($id),
            'customer_id' => $owner->id, 'tenant_id' => $tenant->id, 'kind' => $kind, 'status' => 'issued',
            'total_amount' => $amount, 'currency' => 'IRT',
            'buyer_snapshot' => ['customer_name' => $owner->name, 'business_name' => $tenant->name],
            'issued_at' => now(), 'expires_at' => now()->addYear(), 'period_starts_at' => $start,
            'period_ends_at' => $end, 'due_at' => $due, 'grace_ends_at' => $due->copy()->addDays(7), ...$subject,
        ]);
        foreach ($items as $item) {
            CommerceInvoiceItem::query()->create(['commerce_invoice_id' => $invoice->id, 'commerce_order_item_id' => null,
                'kind' => $item['kind'], 'snapshot' => $item['snapshot'], 'amount' => $item['amount'], 'currency' => 'IRT']);
        }

        return $invoice;
    }

    private function nextMonth(CarbonInterface $start, int $anchorDay): Carbon
    {
        $next = Carbon::parse($start)->startOfMonth()->addMonth();

        return $next->day(min($anchorDay, $next->daysInMonth))->setTimeFrom($start);
    }

    private function nextYear(CarbonInterface $start, int $anchorMonth, int $anchorDay): Carbon
    {
        $next = Carbon::parse($start)->addYear()->month($anchorMonth)->startOfMonth();

        return $next->day(min($anchorDay, $next->daysInMonth))->setTimeFrom($start);
    }
}
