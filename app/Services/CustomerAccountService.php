<?php

namespace App\Services;

use App\Enums\CustomerRole;
use App\Models\Customer;
use App\Models\Tenant;
use App\Support\Permissions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CustomerAccountService
{
    public function createOwner(string $name, string $mobile, string $business): Customer
    {
        validator(compact('name', 'mobile', 'business'), [
            'name' => ['required', 'string', 'max:100'],
            'mobile' => ['required', 'regex:/^\+989\d{9}$/', 'unique:customers,mobile'],
            'business' => ['required', 'string', 'max:255'],
        ])->validate();

        return DB::transaction(function () use ($name, $mobile, $business): Customer {
            $tenant = Tenant::query()->create(['name' => $business, 'status' => 'active']);
            $customer = Customer::query()->create([
                'name' => $name, 'mobile' => $mobile, 'tenant_id' => $tenant->id, 'role' => CustomerRole::Owner,
            ]);
            $tenant->update(['owner_customer_id' => $customer->id]);
            $this->setPermissions($customer, Permissions::CUSTOMER_OWNER_DEFAULTS);
            Log::info('Customer business created', ['customer_id' => $customer->id, 'tenant_id' => $tenant->id]);

            return $customer;
        });
    }

    public function createStaff(Tenant $tenant, string $name, string $mobile, array $permissions): Customer
    {
        validator(compact('name', 'mobile'), [
            'name' => ['required', 'string', 'max:100'],
            'mobile' => ['required', 'regex:/^\+989\d{9}$/', 'unique:customers,mobile'],
        ])->validate();

        return DB::transaction(function () use ($tenant, $name, $mobile, $permissions): Customer {
            $tenant = Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if (! $tenant->isActive() || $tenant->system_key !== null
                || ! $tenant->customerOwner()->where('tenant_id', $tenant->id)->where('role', CustomerRole::Owner)->exists()) {
                throw ValidationException::withMessages(['tenant_id' => 'یک سازمان مشتری فعال انتخاب کنید.']);
            }
            $customer = Customer::query()->create([
                'name' => $name, 'mobile' => $mobile, 'tenant_id' => $tenant->id, 'role' => CustomerRole::Staff,
            ]);
            $this->setPermissions($customer, $permissions);
            Log::info('Customer staff created', ['customer_id' => $customer->id, 'tenant_id' => $tenant->id]);

            return $customer;
        });
    }

    public function setPermissions(Customer $customer, array $permissions): void
    {
        if (array_diff($permissions, Permissions::CUSTOMER_ASSIGNABLE)) {
            throw ValidationException::withMessages(['permissions' => 'دسترسی زیرساختی برای مشتری مجاز نیست.']);
        }
        if (in_array(Permissions::PHONES_MANAGE, $permissions, true)) {
            $permissions[] = Permissions::LINES_VIEW;
        }
        DB::transaction(function () use ($customer, $permissions): void {
            // Serialize grants/revocations with customer checkout authorization.
            $customer = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $customer->permissions()->delete();
            foreach (array_unique($permissions) as $permission) {
                $customer->permissions()->create(['permission' => $permission]);
            }
        });
    }
}
