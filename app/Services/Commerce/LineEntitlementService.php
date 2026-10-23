<?php

namespace App\Services\Commerce;

use App\Models\CallQueue;
use App\Models\CommerceInvoice;
use App\Models\IvrMenu;
use App\Models\NumberAssignment;
use App\Models\NumberSubscription;
use App\Models\NumberTerm;
use App\Models\PaymentAttempt;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use Illuminate\Validation\ValidationException;

class LineEntitlementService
{
    public function subscription(SipNumber $number): ?NumberSubscription
    {
        if ($number->inventory_state === null || ! $number->current_assignment_id) {
            return null;
        }
        $assignment = NumberAssignment::query()->find($number->current_assignment_id);
        if (! $assignment || $assignment->released_at || $assignment->sip_number_id !== $number->id
            || $assignment->tenant_id !== $number->tenant_id) {
            return null;
        }
        $subscription = NumberSubscription::query()->where('number_assignment_id', $assignment->id)
            ->where('tenant_id', $number->tenant_id)->first();
        $invoice = CommerceInvoice::query()->where('commerce_order_id', $assignment->commerce_order_id)
            ->where('tenant_id', $assignment->tenant_id)->where('status', 'paid')
            ->where('paid_payment_attempt_id', $assignment->payment_attempt_id)->first();
        $payment = PaymentAttempt::query()->find($assignment->payment_attempt_id);

        return $subscription && $invoice && $payment?->status === 'settled' && $payment->verified_at && $payment->settled_at
            && $payment->commerce_invoice_id === $invoice->id ? $subscription : null;
    }

    public function allows(SipNumber $number, bool $calls = true): bool
    {
        // Existing non-commerce lines retain their original route checks.
        if ($number->inventory_state === null) {
            return true;
        }
        if ($number->inventory_state !== 'assigned' || $number->status !== 'assigned' || ! $number->enabled
            || ! $number->tenant?->isActive() || ! preg_match('/^\+[1-9][0-9]{3,14}$/D', $number->normalized_number)) {
            return false;
        }
        $gateway = $number->providerGateway;
        if (! $gateway || $gateway->tenant_id !== null || ! $gateway->enabled || $gateway->verification_status !== 'approved'
            || ! $gateway->approved_for_outbound || $gateway->profile !== 'external' || $gateway->context !== 'public'
            || ! preg_match('/^[a-zA-Z0-9_-]+$/D', $gateway->name)
            || ! preg_match('/^[a-zA-Z0-9.\-]+$/D', $gateway->host)
            || ! in_array($gateway->transport, ['udp', 'tcp', 'tls'], true) || $gateway->port < 1 || $gateway->port > 65535) {
            return false;
        }
        $term = $this->annualTerm($number);
        if ($term !== null) {
            $plan = TenantSubscription::query()->where('tenant_id', $number->tenant_id)->first();
            return $plan !== null && $plan->status === 'active' && $plan->grace_ends_at?->isFuture()
                && ($plan->cancel_at === null || $plan->cancel_at->isFuture())
                && $term->status === 'active' && $term->grace_ends_at?->isFuture();
        }
        $subscription = $this->subscription($number);
        if (! $subscription) {
            return false;
        }
        if (! $calls && $subscription->status === 'pending_activation') {
            return true;
        }

        return $subscription->status === 'active' && $subscription->period_starts_at?->lte(now())
            && $subscription->period_ends_at?->gt(now());
    }

    public function assertConfigure(SipNumber $number): void
    {
        if (! $this->allows($number, false)) {
            throw ValidationException::withMessages(['line' => 'این خط مجوز فعال برای تنظیم ندارد؛ وضعیت سفارش یا اشتراک را بررسی کنید.']);
        }
    }

    public function activate(SipNumber $number): void
    {
        if ($number->inventory_state === null) {
            return;
        }
        if ($this->annualTerm($number) !== null) {
            return; // Annual number and tenant terms start when the allocated number is technically ready.
        }
        $this->assertConfigure($number);
        $subscription = NumberSubscription::query()->lockForUpdate()->findOrFail($this->subscription($number)->id);
        if ($subscription->status === 'pending_activation') {
            $subscription->update(['status' => 'active', 'activated_at' => now(), 'period_starts_at' => now(),
                'period_ends_at' => now()->addMonthNoOverflow()]);
            app(CommerceAudit::class)->system('subscription.activated', 'number_subscription', $subscription->id,
                ['sip_number_id' => $number->id], 'Customer saved first answering configuration');
        }
    }

    public function tenantAllows(Tenant $tenant, bool $calls = false): bool
    {
        if ($tenant->system_key === 'blucom' || $tenant->owner_customer_id === null) {
            return true;
        }

        return SipNumber::query()->where('tenant_id', $tenant->id)->where('status', 'assigned')->get()
            ->contains(fn ($number) => $number->inventory_state === null ? $number->enabled : $this->allows($number, $calls));
    }

    /** Tenant-wide maximum across eligible purchased plans; purchases do not silently multiply limits. */
    public function limits(Tenant $tenant): array
    {
        $tenantSubscription = TenantSubscription::query()->where('tenant_id', $tenant->id)->first();
        if ($tenantSubscription !== null && $tenantSubscription->status === 'active' && $tenantSubscription->grace_ends_at?->isFuture()
            && ($tenantSubscription->cancel_at === null || $tenantSubscription->cancel_at->isFuture())) {
            $limits = $tenantSubscription->planVersion?->limits ?? [];
            return array_map('intval', array_intersect_key($limits, array_flip(['numbers', 'extensions', 'queues', 'ivr_menus'])));
        }
        $limits = ['extensions' => 0, 'queues' => 0, 'ivr_menus' => 0];
        foreach (SipNumber::query()->where('tenant_id', $tenant->id)->whereNotNull('inventory_state')->get() as $number) {
            if (! $this->allows($number, false)) {
                continue;
            }
            foreach ($limits as $key => $value) {
                $limits[$key] = max($value, (int) ($this->subscription($number)->snapshot['limits'][$key] ?? 0));
            }
        }

        return $limits;
    }

    public function assertCapacity(Tenant $tenant, string $resource): void
    {
        Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
        if ($tenant->owner_customer_id === null || SipNumber::query()->where('tenant_id', $tenant->id)->whereNull('inventory_state')->where('status', 'assigned')->exists()) {
            return;
        }
        $model = match ($resource) {
            'extensions' => SipExtension::class, 'queues' => CallQueue::class, 'ivr_menus' => IvrMenu::class,
        };
        $used = $model::query()->where('tenant_id', $tenant->id)->where('enabled', true)->count();
        $limit = $this->limits($tenant)[$resource] ?? 0;
        if ($used >= $limit) {
            $label = match ($resource) {'extensions' => 'داخلی', 'queues' => 'تیم پاسخ‌گویی', 'ivr_menus' => 'منوی تماس'};
            throw ValidationException::withMessages(['line' => "{$used} از {$limit} {$label} فعال است. یکی را غیرفعال کنید یا از بخش اشتراک و صورتحساب‌ها پلن را ارتقا دهید."]);
        }
    }

    public function annualTerm(SipNumber $number): ?NumberTerm
    {
        if ($number->current_assignment_id === null || $number->tenant_id === null) {
            return null;
        }
        $assignment = NumberAssignment::query()->find($number->current_assignment_id);
        if (! $assignment || $assignment->released_at !== null || $assignment->tenant_id !== $number->tenant_id
            || $assignment->sip_number_id !== $number->id) {
            return null;
        }
        $term = NumberTerm::query()->where('number_assignment_id', $assignment->id)->where('tenant_id', $number->tenant_id)->first();
        $payment = PaymentAttempt::query()->find($assignment->payment_attempt_id);
        $invoice = CommerceInvoice::query()->where('commerce_order_id', $assignment->commerce_order_id)
            ->where('tenant_id', $number->tenant_id)->where('paid_payment_attempt_id', $assignment->payment_attempt_id)
            ->where('status', 'paid')->first();

        return $term && $payment?->status === 'settled' && $payment->verified_at && $payment->settled_at
            && $invoice && $payment->commerce_invoice_id === $invoice->id ? $term : null;
    }
}
