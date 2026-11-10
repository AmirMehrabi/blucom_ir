<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\PickupGroup;
use App\Models\Tenant;
use App\Services\TenantService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class CallPickupController extends Controller
{
    public function index(Request $request, TenantService $tenants)
    {
        $tenant = $tenants->forUser($request->user('customer'));

        return response()->view('customer.extensions.pickup', [
            'tenant' => $tenant,
            'groups' => PickupGroup::query()->where('tenant_id', $tenant->id)->orderBy('name')->get(),
            'extensions' => $tenant->sipExtensions()->orderBy('extension')->get(),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, TenantService $tenants)
    {
        $tenant = $tenants->forUser($request->user('customer'));
        DB::transaction(function () use ($request, $tenant) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $data = $request->validate([
                'pickup_enabled' => ['required', 'boolean'], 'pickup_directed' => ['required', 'boolean'], 'pickup_group' => ['required', 'boolean'],
                'new_group' => ['nullable', 'string', 'max:100'],
                'delete_group' => ['nullable', 'integer', Rule::exists('pickup_groups', 'id')->where('tenant_id', $tenant->id)],
                'extensions' => ['sometimes', 'array'],
                'extensions.*.id' => ['required', 'integer', 'distinct', Rule::exists('sip_extensions', 'id')->where('tenant_id', $tenant->id)],
                'extensions.*.pickup_group_id' => ['nullable', 'integer', Rule::exists('pickup_groups', 'id')->where('tenant_id', $tenant->id)],
                'extensions.*.pickup_allowed' => ['required', 'boolean'],
            ]);
            $tenant->update(collect($data)->only(['pickup_enabled', 'pickup_directed', 'pickup_group'])->all());
            foreach ($data['extensions'] ?? [] as $extension) {
                $tenant->sipExtensions()->findOrFail($extension['id'])->update(collect($extension)->only(['pickup_group_id', 'pickup_allowed'])->all());
            }
            if (! empty($data['delete_group'])) {
                PickupGroup::query()->where('tenant_id', $tenant->id)->findOrFail($data['delete_group'])->delete();
            }
            if (! empty($data['new_group'])) {
                PickupGroup::create(['tenant_id' => $tenant->id, 'name' => $data['new_group']]);
            }
            Log::info('Call pickup settings updated', ['tenant_id' => $tenant->id, 'actor_id' => $request->user('customer')->id]);
        });

        return redirect()->route('customer.extensions.pickup')->with('status', 'تنظیمات پاسخ‌گویی به تماس همکار ذخیره شد.');
    }
}
