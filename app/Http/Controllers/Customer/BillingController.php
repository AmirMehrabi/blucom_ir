<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CallQueue;
use App\Models\CommerceInvoice;
use App\Models\IvrMenu;
use App\Models\NumberSubscription;
use App\Models\NumberTerm;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Models\PlanVersion;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\TenantSubscription;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\CustomerCommercePresenter;
use App\Services\Commerce\CustomerNumberReleaseService;
use App\Services\Commerce\SubscriptionBillingService;
use App\Services\Portal\CustomerBillingSummary;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BillingController extends Controller
{
    public function invoice(Request $request, string $invoice)
    {
        $customer = $request->user('customer');
        abort_unless($customer->hasPermission(Permissions::BILLING_VIEW), 403);
        $record = CommerceInvoice::query()->with(['items'])
            ->where('public_id', $invoice)->where('tenant_id', $customer->tenant_id)->firstOrFail();
        $payment = $record->paid_payment_attempt_id
            ? PaymentAttempt::query()->find($record->paid_payment_attempt_id) : null;

        return response()->view('customer.billing.invoice', ['invoice' => $record, 'payment' => $payment, 'invoiceAction' => app(CustomerBillingSummary::class)->action($record, $customer), 'paymentIsTest' => app(CustomerCommercePresenter::class)->paymentMode()['isTest']])
            ->header('Cache-Control', 'no-store, private');
    }

    public function index(Request $request)
    {
        $customer = $request->user('customer');
        abort_unless($customer->hasPermission(Permissions::BILLING_VIEW), 403);
        $tab = $request->validate(['tab' => ['nullable', 'in:overview,invoices,orders,subscription']])['tab'] ?? 'overview';
        $tenantId = $customer->tenant_id;
        $subscription = TenantSubscription::query()->with('planVersion.plan')->where('tenant_id', $tenantId)->first();
        $terms = NumberTerm::query()->with('assignment.sipNumber')->where('tenant_id', $tenantId)->latest('id')->get();
        $invoices = CommerceInvoice::query()->with('items')->where('tenant_id', $tenantId)->latest('id')->paginate(20)->withQueryString();
        $versions = PlanVersion::query()->with('plan')->whereNotNull('published_at')->whereNotNull('monthly_price')
            ->whereHas('plan', fn ($query) => $query->where('archived', false))->orderBy('monthly_price')->get();
        $used = [
            'numbers' => SipNumber::query()->where('tenant_id', $tenantId)->where('inventory_state', 'assigned')->count(),
            'extensions' => SipExtension::query()->where('tenant_id', $tenantId)->where('enabled', true)->count(),
            'queues' => CallQueue::query()->where('tenant_id', $tenantId)->where('enabled', true)->count(),
            'ivr_menus' => IvrMenu::query()->where('tenant_id', $tenantId)->where('enabled', true)->count(),
        ];
        $gateway = PaymentGateway::query()->where('active', true)->with('currentVersion')->first();
        $paymentIsTest = $gateway?->provider === 'zibal' && ($gateway->currentVersion?->isTest() ?? false);

        $billing = app(CustomerBillingSummary::class);
        $nextInvoice = $billing->next($customer);
        $nextAction = $nextInvoice ? $billing->action($nextInvoice, $customer) : null;
        $invoiceActions = $invoices->getCollection()->mapWithKeys(fn ($invoice) => [$invoice->id => $billing->action($invoice, $customer)]);
        $legacySubscriptions = NumberSubscription::query()->with('assignment.sipNumber')->where('tenant_id', $tenantId)->get();
        $orders = null;
        if ($tab === 'orders') {
            $orders = app(CheckoutService::class)->orders($customer)->withQueryString();
            $orders->setCollection($orders->getCollection()->map(fn ($order) => app(CustomerCommercePresenter::class)->order($order, $customer)));
        }

        return response()->view('customer.billing.index', compact('subscription', 'terms', 'invoices', 'versions', 'used', 'paymentIsTest', 'tab', 'nextInvoice', 'nextAction', 'invoiceActions', 'legacySubscriptions', 'orders'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function changePlan(Request $request, SubscriptionBillingService $billing)
    {
        $data = $request->validate(['plan_version_id' => ['required', 'integer', 'exists:plan_versions,id']]);
        try {
            $invoice = $billing->requestChange($request->user('customer'), (int) $data['plan_version_id']);
        } catch (ValidationException $exception) {
            return back()->withErrors($exception->errors());
        }

        return redirect()->route('customer.billing.index')->with('status', $invoice
            ? 'صورتحساب ارتقا صادر شد. پس از پرداخت تأییدشده، ظرفیت جدید فعال می‌شود.'
            : 'تغییر پلن برای دوره بعد ثبت شد. پیش از تمدید، ظرفیت منابع دوباره بررسی می‌شود.');
    }

    public function cancel(Request $request, SubscriptionBillingService $billing)
    {
        $billing->cancelPlan($request->user('customer'));

        return redirect()->route('customer.billing.index')->with('status', 'اشتراک در پایان دوره پرداخت‌شده متوقف می‌شود. شماره‌ها تا پایان دوره سالانه خود محفوظ می‌مانند.');
    }

    public function undoCancel(Request $request, SubscriptionBillingService $billing)
    {
        $billing->cancelPlan($request->user('customer'), true);

        return redirect()->route('customer.billing.index')->with('status', 'درخواست لغو اشتراک بازگردانده شد.');
    }

    public function releaseNumber(Request $request, int $number, CustomerNumberReleaseService $release)
    {
        $data = $request->validate([
            'assignment_id' => ['required', 'integer', 'min:1'], 'revision' => ['required', 'integer', 'min:1'],
            'confirmed' => ['accepted'],
        ]);
        $release->release($request->user('customer'), $number, (int) $data['assignment_id'], (int) $data['revision']);

        return redirect()->route('customer.billing.index')->with('status', 'شماره آزاد شد. دوره سالانه باقی‌مانده بازپرداخت خودکار ندارد.');
    }

    public function reactivate(Request $request, SubscriptionBillingService $billing)
    {
        $billing->requestReactivation($request->user('customer'));

        return redirect()->route('customer.billing.index')->with('status', 'صورتحساب بازفعال‌سازی صادر شد. پس از پرداخت تأییدشده تماس‌ها بازمی‌گردند.');
    }
}
