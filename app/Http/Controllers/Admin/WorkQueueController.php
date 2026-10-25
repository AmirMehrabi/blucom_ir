<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingFulfillmentRequest;
use App\Models\LineConnectionRequest;
use App\Models\Tenant;
use App\Services\Commerce\CommerceAudit;
use App\Services\Portal\AdminWorkQueue;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class WorkQueueController extends Controller
{
    public function index(Request $request, AdminWorkQueue $queue)
    {
        $data = $request->validate(['type' => ['nullable', Rule::in(array_keys(AdminWorkQueue::TYPES))], 'q' => ['nullable', 'string', 'max:100'], 'tenant_id' => ['nullable', 'integer']]);
        $all = $queue->rows($request->filled('tenant_id') ? $request->integer('tenant_id') : null)->filter(fn ($row) => (! $request->filled('tenant_id') || $row['tenant'] === $request->integer('tenant_id'))
            && (! $request->filled('q') || mb_stripos(implode(' ', [$row['title'], $row['description'], $row['business'], $row['customer']]), $data['q']) !== false));
        $counts = ['all' => $all->count()];
        foreach (AdminWorkQueue::TYPES as $key => $label) {
            if ($key !== 'all') {
                $counts[$key] = $all->where('kind', $key)->count();
            }
        }
        $filtered = $all->filter(fn ($row) => ! $request->filled('type') || $data['type'] === 'all' || $row['kind'] === $data['type'])->values();
        $page = max(1, $request->integer('page', 1));
        $rows = new LengthAwarePaginator($filtered->forPage($page, 30), $filtered->count(), 30, $page, ['path' => route('admin.work.index'), 'query' => $request->query()]);

        return response()->view('admin.work.index', compact('rows', 'counts') + ['contextTenant' => $request->filled('tenant_id') ? Tenant::query()->find($request->integer('tenant_id')) : null])->header('Cache-Control', 'private, no-store');
    }

    private function record(string $kind, int $id)
    {
        abort_unless(in_array($kind, ['connection', 'request']), 404);

        return ($kind === 'connection' ? LineConnectionRequest::query() : BillingFulfillmentRequest::query())->with(['customer.tenant'])->findOrFail($id);
    }

    public function show(string $kind, int $id)
    {
        return response()->view('admin.work.show', ['record' => $this->record($kind, $id), 'kind' => $kind])->header('Cache-Control', 'private, no-store');
    }

    public function resolve(Request $request, string $kind, int $id)
    {
        $data = $request->validate(['resolution' => ['required', 'string', 'min:10', 'max:2000'], 'confirmed' => ['accepted']]);
        DB::transaction(function () use ($request, $kind, $id, $data) {
            $record = $this->record($kind, $id)->newQuery()->lockForUpdate()->findOrFail($id);
            abort_unless($record->status === 'open', 409, 'این درخواست قبلاً بررسی شده است.');
            $record->update(['status' => 'resolved', 'resolution' => $data['resolution'], 'resolved_by_user_id' => $request->user('web')->id, 'resolved_at' => now()]);
            app(CommerceAudit::class)->record($request->user('web'), 'request.resolved', $kind === 'connection' ? 'line_connection_request' : 'billing_fulfillment_request', $id, $data['resolution']);
        });

        return redirect()->route('admin.work.show', compact('kind', 'id'))->with('status', 'نتیجه بررسی ثبت شد. این اقدام عملیات بانکی یا فعال‌سازی خط انجام نمی‌دهد.');
    }
}
