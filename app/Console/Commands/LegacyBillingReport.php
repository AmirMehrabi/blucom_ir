<?php

namespace App\Console\Commands;

use App\Models\CommerceOrder;
use App\Models\NumberAssignment;
use App\Models\NumberSubscription;
use App\Models\SipNumber;
use Illuminate\Console\Command;

class LegacyBillingReport extends Command
{
    protected $signature = 'commerce:legacy-billing-report {--tenant= : Limit report to one tenant ID}';

    protected $description = 'Read-only classification of legacy lines and tenant cutover dates';

    public function handle(): int
    {
        $tenantId = $this->option('tenant');
        if ($tenantId !== null && (! ctype_digit((string) $tenantId) || (int) $tenantId < 1)) {
            $this->error('Tenant must be a positive integer.');
            return self::INVALID;
        }
        $query = NumberAssignment::query()->whereNull('released_at')->orderBy('tenant_id')->orderBy('id');
        if ($tenantId !== null) {
            $query->where('tenant_id', (int) $tenantId);
        }
        $groups = [];
        foreach ($query->get() as $assignment) {
            $subscription = NumberSubscription::query()->where('number_assignment_id', $assignment->id)->first();
            if (! $subscription) {
                continue;
            }
            $entry = [
                'assignment_id' => $assignment->id, 'number_id' => $assignment->sip_number_id,
                'status' => $subscription->status,
                'paid_through_utc' => $subscription->period_ends_at?->toIso8601String(),
                'needs_manual_review' => $subscription->period_ends_at === null || $subscription->status !== 'active',
            ];
            $groups[$assignment->tenant_id][] = $entry;
        }
        $tenants = [];
        foreach ($groups as $id => $lines) {
            $dates = array_filter(array_column($lines, 'paid_through_utc'));
            $cutover = $dates ? max($dates) : null;
            $tenants[] = [
                'tenant_id' => $id, 'latest_paid_through_utc' => $cutover,
                'legacy_lines' => $lines,
                'bridge_line_ids' => $cutover === null ? [] : array_values(array_map(
                    fn ($line) => $line['number_id'],
                    array_filter($lines, fn ($line) => $line['paid_through_utc'] !== null && $line['paid_through_utc'] < $cutover),
                )),
            ];
        }
        $output = [
            'generated_at_utc' => now()->toIso8601String(),
            'tenants' => $tenants,
            'paid_unfulfilled_order_ids' => CommerceOrder::query()->whereIn('status', ['paid_pending_allocation', 'paid_unfulfilled'])
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', (int) $tenantId))->pluck('id')->all(),
            'non_commerce_number_ids' => SipNumber::query()->whereNull('inventory_state')->whereNotNull('tenant_id')
                ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', (int) $tenantId))->pluck('id')->all(),
        ];
        $this->line(json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        return self::SUCCESS;
    }
}
