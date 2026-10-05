<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CustomerRole;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\SipExtension;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerAccountService;
use App\Support\Permissions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CustomerManagementController extends Controller
{
    public function __construct(private readonly CustomerAccountService $accounts) {}

    public function index(): View
    {
        return view('admin.customers.index', [
            'customers' => Customer::query()->with(['tenant', 'permissions'])->orderBy('name')->paginate(30),
            'tenants' => Tenant::query()->whereNull('system_key')->whereNotNull('owner_customer_id')
                ->where('status', 'active')->orderBy('name')->get(),
            'extensions' => SipExtension::query()->where('enabled', true)->orderBy('extension')->get(),
            'permissionLabels' => array_intersect_key(Permissions::LABELS, array_flip(array_diff(Permissions::CUSTOMER_ASSIGNABLE,
                [Permissions::NUMBERS_PURCHASE, Permissions::BILLING_VIEW, Permissions::BILLING_MANAGE]))),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'mobile' => ['required', 'string', 'max:20'],
            'role' => ['required', Rule::enum(CustomerRole::class)],
            'business' => ['required_if:role,owner', 'prohibited_unless:role,owner', 'string', 'max:255'],
            'tenant_id' => ['required_if:role,staff', 'prohibited_unless:role,staff', 'integer', 'exists:tenants,id'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::CUSTOMER_ASSIGNABLE)],
        ]);
        $mobile = $this->mobile($data['mobile']);
        if ($data['role'] === CustomerRole::Owner->value) {
            $this->accounts->createOwner($data['name'], $mobile, $data['business']);
        } else {
            $this->accounts->createStaff(Tenant::query()->findOrFail($data['tenant_id']), $data['name'], $mobile,
                $data['permissions'] ?? Permissions::CUSTOMER_STAFF_DEFAULTS);
        }

        return redirect()->route('admin.customers.index')->with('status', 'حساب مشتری ساخته شد. ورود از '.config('portal.customer_domain').' انجام می‌شود.');
    }

    public function update(Request $request, Customer $customer): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'], 'enabled' => ['required', 'boolean'],
            'mobile' => ['prohibited'], 'tenant_id' => ['prohibited'], 'role' => ['prohibited'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', Rule::in(Permissions::CUSTOMER_ASSIGNABLE)],
            'sip_extension_id' => ['nullable', 'integer'],
        ]);
        DB::transaction(function () use ($customer, $data): void {
            $customer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $extensionId = $data['sip_extension_id'] ?? null;
            if ($extensionId !== null) {
                $extension = SipExtension::query()->whereKey($extensionId)->where('tenant_id', $customer->tenant_id)
                    ->where('enabled', true)->lockForUpdate()->first();
                if ($extension === null || Customer::query()->where('sip_extension_id', $extensionId)->whereKeyNot($customer->id)->exists()
                    || User::query()->where('sip_extension_id', $extensionId)->exists()) {
                    throw ValidationException::withMessages(['sip_extension_id' => 'داخلی باید فعال، آزاد و متعلق به سازمان مشتری باشد.']);
                }
            }
            $customer->update([
                'name' => $data['name'], 'disabled_at' => $data['enabled'] ? null : now(), 'sip_extension_id' => $extensionId,
            ]);
            $this->accounts->setPermissions($customer, $data['permissions'] ?? []);
            Log::info('Customer access updated', ['customer_id' => $customer->id, 'tenant_id' => $customer->tenant_id]);
        });

        return redirect()->route('admin.customers.index')->with('status', 'دسترسی مشتری ذخیره شد.');
    }

    public function updateBusiness(Request $request, Tenant $tenant): RedirectResponse
    {
        abort_unless($tenant->system_key === null && $tenant->owner_customer_id !== null, 404);
        $data = $request->validate(['status' => ['required', Rule::in(['active', 'disabled'])]]);
        $tenant->update($data);
        Log::info('Customer business status changed', [
            'tenant_id' => $tenant->id, 'status' => $data['status'], 'user_id' => $request->user()->id,
        ]);

        return redirect()->route('admin.customers.index')->with('status', 'وضعیت سازمان مشتری ذخیره شد.');
    }

    private function mobile(string $value): string
    {
        $value = preg_replace('/[\s\-()]/', '', $value);
        if (str_starts_with($value, '09')) {
            $value = '+98'.substr($value, 1);
        } elseif (str_starts_with($value, '989')) {
            $value = '+'.$value;
        }
        if (! preg_match('/^\+989\d{9}$/', $value)) {
            throw ValidationException::withMessages(['mobile' => 'شماره موبایل معتبر نیست.']);
        }

        return $value;
    }
}
