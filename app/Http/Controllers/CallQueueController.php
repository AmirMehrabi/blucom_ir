<?php

namespace App\Http\Controllers;

use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\SipExtension;
use App\Models\Tenant;
use App\Services\Commerce\LineEntitlementService;
use App\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CallQueueController extends Controller
{
    public function __construct(private readonly TenantService $tenants) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);
        $todayUtc = now(config('voip.display_timezone', 'Asia/Tehran'))->startOfDay()->utc();

        return view('call-queues.index', [
            'queues' => CallQueue::query()->whereBelongsTo($tenant)->with([
                'members' => fn ($query) => $query->where('sip_extensions.tenant_id', $tenant->id),
                'fallbackExtension' => fn ($query) => $query->where('tenant_id', $tenant->id),
            ])
                ->withCount([
                    'callRecords as answered_today_count' => fn ($query) => $query->where('started_at', '>=', $todayUtc)->where('queue_outcome', 'answered'),
                    'callRecords as missed_today_count' => fn ($query) => $query->where('started_at', '>=', $todayUtc)->where('queue_outcome', 'cancel'),
                ])->orderBy('name')->get(),
            'extensions' => SipExtension::query()->whereBelongsTo($tenant)->where('enabled', true)->orderBy('extension')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $data = $this->validated($request, $tenant->id);
        $members = $this->members($data, $tenant->id);
        DB::transaction(function () use ($tenant, $data, $members): void {
            app(LineEntitlementService::class)->assertCapacity($tenant, 'queues');
            $queue = CallQueue::query()->create([
                'tenant_id' => $tenant->id,
                'name' => $data['name'],
                'strategy' => $data['strategy'],
                'max_wait_seconds' => $data['max_wait_seconds'],
                'fallback_extension_id' => $data['fallback_extension_id'] ?? null,
                'enabled' => true,
            ]);
            $queue->members()->sync($members);
            Log::info('Call team created', ['queue_id' => $queue->id]);
        });

        return back()->with('status', 'تیم پاسخ‌گویی ساخته شد.');
    }

    public function update(Request $request, int $queue): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $record = CallQueue::query()->whereBelongsTo($tenant)->findOrFail($queue);
        $data = $this->validated($request, $tenant->id, $record->id);
        $members = $this->members($data, $tenant->id);
        DB::transaction(function () use ($record, $data, $members): void {
            if ($data['enabled'] && ! $record->enabled) {
                app(LineEntitlementService::class)->assertCapacity($record->tenant, 'queues');
            }
            $record->update([
                'name' => $data['name'],
                'strategy' => $data['strategy'],
                'max_wait_seconds' => $data['max_wait_seconds'],
                'fallback_extension_id' => $data['fallback_extension_id'] ?? null,
                'enabled' => (bool) $data['enabled'],
            ]);
            $record->members()->sync($members);
            Log::info('Call team updated', ['queue_id' => $record->id]);
        });

        return back()->with('status', 'تنظیمات تیم ذخیره شد.');
    }

    public function destroy(Request $request, int $queue): RedirectResponse
    {
        $record = CallQueue::query()->whereBelongsTo($this->tenant($request))->findOrFail($queue);
        if (InboundRoute::query()->where(fn ($query) => $query
            ->where('destination_type', InboundRoute::DESTINATION_QUEUE)->where('destination_id', $record->id))
            ->orWhere(fn ($query) => $query->where('closed_destination_type', InboundRoute::DESTINATION_QUEUE)
                ->where('closed_destination_id', $record->id))->exists()) {
            throw ValidationException::withMessages(['queue' => 'ابتدا شماره‌ای را که به این تیم وصل است به مقصد دیگری منتقل کنید.']);
        }
        if ($record->callRecords()->exists()) {
            throw ValidationException::withMessages(['queue' => 'این تیم سابقه تماس دارد. برای حفظ گزارش‌ها، تیم را غیرفعال کنید.']);
        }
        $record->delete();
        Log::info('Call team deleted', ['queue_id' => $record->id]);

        return back()->with('status', 'تیم حذف شد.');
    }

    private function validated(Request $request, int $tenantId, ?int $queueId = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('call_queues')->where('tenant_id', $tenantId)->ignore($queueId)],
            'strategy' => ['required', Rule::in(array_keys(CallQueue::STRATEGIES))],
            'max_wait_seconds' => ['required', 'integer', 'between:15,600'],
            'fallback_extension_id' => ['nullable', 'integer', Rule::exists('sip_extensions', 'id')->where('tenant_id', $tenantId)->where('enabled', true)],
            'member_ids' => ['required', 'array', 'min:1'],
            'member_ids.*' => ['required', 'integer', 'distinct'],
            'enabled' => [$queueId === null ? 'sometimes' : 'required', 'boolean'],
        ]);
    }

    private function members(array $data, int $tenantId): array
    {
        $ids = array_map('intval', $data['member_ids']);
        $count = SipExtension::query()->where('tenant_id', $tenantId)->where('enabled', true)->whereIn('id', $ids)->count();
        if ($count !== count($ids)) {
            throw ValidationException::withMessages(['member_ids' => 'فقط داخلی‌های فعال همین مجموعه را انتخاب کنید.']);
        }

        return $ids;
    }

    private function tenant(Request $request): Tenant
    {
        return $this->tenants->forUser($request->user());
    }
}
