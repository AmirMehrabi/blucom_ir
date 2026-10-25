<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommerceInvoice;
use App\Models\PaymentAttempt;
use App\Models\Tenant;
use App\Services\Commerce\MellatPaymentService;
use App\Services\Commerce\RecurringPaymentService;
use App\Services\Commerce\ZibalPaymentService;
use Illuminate\Http\Request;

class PaymentReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->validate(['status' => ['nullable', 'in:'.implode(',', array_keys(PaymentAttempt::statusLabels()))],
            'mode' => ['nullable', 'in:live,test'], 'invoice_id' => ['nullable', 'integer']]);
        $attempts = PaymentAttempt::query()->when($filter['invoice_id'] ?? null, fn ($q, $id) => $q->where('commerce_invoice_id', $id))->when($filter['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filter['mode'] ?? null, fn ($query, $mode) => $query->where('is_test', $mode === 'test'))
            ->latest('id')->paginate(30)->withQueryString();
        $invoices = CommerceInvoice::query()->whereIn('id', $attempts->pluck('commerce_invoice_id'))->get()->keyBy('id');

        $contextTenant = $request->filled('invoice_id') ? Tenant::query()->find(CommerceInvoice::query()->find($request->integer('invoice_id'))?->tenant_id) : null;

        return response()->view('admin.commerce.payments', compact('attempts', 'invoices', 'contextTenant'))->header('Cache-Control', 'no-store, private');
    }

    public function reconcile(Request $request, int $attempt, MellatPaymentService $mellat, ZibalPaymentService $zibal, RecurringPaymentService $recurring)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $provider = PaymentAttempt::query()->findOrFail($attempt)->provider;
        abort_unless(in_array($provider, ['mellat', 'zibal'], true), 404);
        $invoice = CommerceInvoice::query()->findOrFail(PaymentAttempt::query()->findOrFail($attempt)->commerce_invoice_id);
        $result = $invoice->commerce_order_id === null
            ? $recurring->reconcile($request->user('web'), $attempt, $data['reason'])
            : ($provider === 'zibal'
            ? $zibal->reconcile($request->user('web'), $attempt, $data['reason'])
            : $mellat->reconcile($request->user('web'), $attempt, $data['reason']));

        return redirect()->route('admin.payments.index', ['invoice_id' => $invoice->id])->with('status', 'بررسی بانکی انجام شد. وضعیت: '.$result->statusLabel());
    }

    public function reverse(Request $request, int $attempt, MellatPaymentService $payments)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $invoice = CommerceInvoice::query()->findOrFail(PaymentAttempt::query()->findOrFail($attempt)->commerce_invoice_id);
        abort_if($invoice->commerce_order_id === null, 409, 'Renewal reversals require separate bank review.');
        $result = $payments->reverse($request->user('web'), $attempt, $data['reason']);

        return redirect()->route('admin.payments.index', ['invoice_id' => $invoice->id])->with('status', 'بررسی برگشت انجام شد. وضعیت: '.$result->statusLabel());
    }
}
