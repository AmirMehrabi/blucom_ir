<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CustomerRole;
use App\Http\Controllers\Controller;
use App\Models\BillingFulfillmentRequest;
use App\Models\CommerceInvoice;
use App\Models\CommerceOrder;
use App\Models\Customer;
use App\Models\LineConnectionRequest;
use App\Models\PaymentAttempt;
use App\Models\SipExtension;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Models\User;
use App\Models\WalletEntry;
use App\Services\Commerce\WalletService;
use App\Services\CustomerAccountService;
use App\Services\Portal\AdminWorkQueue;
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

    public function index(Request $request): View
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'edit' => ['nullable', 'integer']]);

        return view('admin.customers.index', [
            'customers' => Customer::query()->when($request->filled('q'), fn ($q) => $q->where(fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%')->orWhere('mobile', 'like', '%'.$request->string('q').'%')->orWhereHas('tenant', fn ($q) => $q->where('name', 'like', '%'.$request->string('q').'%'))))->with(['tenant', 'permissions'])->orderBy('name')->paginate(30)->withQueryString(),
            'tenants' => Tenant::query()->whereNull('system_key')->whereNotNull('owner_customer_id')
                ->where('status', 'active')->orderBy('name')->get(),
            'extensions' => SipExtension::query()->where('enabled', true)->orderBy('extension')->get(),
            'permissionLabels' => array_intersect_key(Permissions::LABELS, array_flip(array_diff(Permissions::CUSTOMER_ASSIGNABLE,
                [Permissions::NUMBERS_PURCHASE, Permissions::BILLING_VIEW, Permissions::BILLING_MANAGE]))),
        ]);
    }

    public function show(Request $request, Customer $customer)
    {
        $tenant = $customer->tenant;
        abort_unless($tenant && $tenant->system_key === null, 404);
        $tab = $request->validate(['tab' => ['nullable', Rule::in(['overview', 'lines', 'billing', 'orders', 'activity', 'access'])]])['tab'] ?? 'overview';
        $numbers = $tenant->sipNumbers()->with(['inboundRoute.destination', 'providerGateway'])->orderBy('normalized_number')->get();
        $phones = $tenant->sipExtensions()->orderBy('extension')->get();
        $orders = CommerceOrder::query()->where('tenant_id', $tenant->id)->with(['item', 'invoice'])->latest('id')->paginate(20)->withQueryString();
        $invoices = CommerceInvoice::query()->where('tenant_id', $tenant->id)->latest('id')->paginate(20, ['*'], 'invoice_page')->withQueryString();
        $payments = PaymentAttempt::query()->whereIn('commerce_invoice_id', CommerceInvoice::query()->where('tenant_id', $tenant->id)->select('id'))->latest('id')->limit(20)->get();
        $subscription = TenantSubscription::query()->with('planVersion.plan')->where('tenant_id', $tenant->id)->first();
        $members = $tenant->customers()->with('permissions')->get();
        $events = DB::table('commerce_audit_events')->where(fn ($q) => $q->whereIn('actor_customer_id', $members->pluck('id'))
            ->orWhere(fn ($q) => $q->where('resource_type', 'sip_number')->whereIn('resource_id', $numbers->pluck('id')))
            ->orWhere(fn ($q) => $q->where('resource_type', 'line_connection_request')->whereIn('resource_id', LineConnectionRequest::query()->where('tenant_id', $tenant->id)->select('id')))
            ->orWhere(fn ($q) => $q->where('resource_type', 'billing_fulfillment_request')->whereIn('resource_id', BillingFulfillmentRequest::query()->where('tenant_id', $tenant->id)->select('id'))))
            ->latest('id')->limit(50)->get(['event', 'reason', 'created_at']);
        $openTasks = app(AdminWorkQueue::class)->rows($tenant->id)->where('tenant', $tenant->id)->count();

        $walletBalance = config('commerce.wallet_enabled') ? app(WalletService::class)->balance($tenant->id) : 0;
        $walletEntries = config('commerce.wallet_enabled') ? WalletEntry::query()->with('invoice')->where('tenant_id', $tenant->id)->latest('id')->paginate(20, ['*'], 'wallet_page')->withQueryString() : null;

        return response()->view('admin.customers.show', compact('customer', 'tenant', 'tab', 'numbers', 'phones', 'orders', 'invoices', 'payments', 'subscription', 'members', 'events', 'openTasks', 'walletBalance', 'walletEntries'))->header('Cache-Control', 'private, no-store');
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
