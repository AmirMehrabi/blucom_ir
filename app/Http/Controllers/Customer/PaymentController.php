<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\CommerceInvoice;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\CustomerCommercePresenter;
use App\Services\Commerce\MellatPaymentService;
use App\Services\Commerce\RecurringPaymentService;
use App\Services\Commerce\MellatSoapClient;
use App\Services\Commerce\ZibalPaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function initiate(Request $request, string $invoice, MellatPaymentService $mellat, ZibalPaymentService $zibal, RecurringPaymentService $recurring)
    {
        $isRecurring = CommerceInvoice::query()->where('public_id', $invoice)->whereNull('commerce_order_id')->exists();
        try {
            $data = $request->validate(['idempotency_key' => ['required', 'uuid'], 'payment_mode' => ['sometimes', 'required', 'in:live,test']]);
            $provider = PaymentGateway::query()->where('active', true)->value('provider');
            abort_unless(in_array($provider, ['mellat', 'zibal'], true), 404);
            if ($provider === 'mellat' && ($data['payment_mode'] ?? 'live') === 'test') {
                throw ValidationException::withMessages(['payment' => 'حالت پرداخت تغییر کرده است؛ صفحه سفارش را تازه کنید.']);
            }
            $attempt = $isRecurring
                ? $recurring->initiate($request->user('customer'), $invoice, $data['idempotency_key'],
                    isset($data['payment_mode']) ? $data['payment_mode'] === 'test' : null)
                : ($provider === 'zibal'
                    ? $zibal->initiate($request->user('customer'), $invoice, $data['idempotency_key'],
                        isset($data['payment_mode']) ? $data['payment_mode'] === 'test' : null)
                    : $mellat->initiate($request->user('customer'), $invoice, $data['idempotency_key']));
        } catch (ValidationException) {
            return redirect()->route($isRecurring ? 'customer.billing.index' : 'customer.orders.index')
                ->withErrors(['payment' => 'پرداخت این صورتحساب در حال حاضر امکان‌پذیر نیست. وضعیت و مبلغ آن را بررسی کنید یا با پشتیبانی تماس بگیرید.']);
        }

        return redirect()->route('customer.payments.show', $attempt->public_id);
    }

    public function show(Request $request, string $attempt, CheckoutService $checkout, CustomerCommercePresenter $presenter)
    {
        $payment = PaymentAttempt::query()->where('public_id', $attempt)->firstOrFail();
        $invoice = CommerceInvoice::query()->findOrFail($payment->commerce_invoice_id);
        if ($invoice->commerce_order_id === null) {
            abort_unless($invoice->tenant_id === $request->user('customer')->tenant_id
                && $request->user('customer')->hasPermission(\App\Support\Permissions::BILLING_VIEW), 404);
            $canPay = $payment->status === 'redirect_ready' && $invoice->status === 'issued';
            if ($canPay && $payment->provider === 'zibal' && preg_match('/^[1-9][0-9]{0,18}$/D', (string) $payment->ref_id)) {
                return redirect()->away('https://gateway.zibal.ir/start/'.$payment->ref_id)
                    ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
            }
            return response()->view('customer.billing.payment', ['invoice' => $invoice, 'payment' => $payment,
                'canPay' => $canPay, 'bankUrl' => MellatSoapClient::PAYMENT_URL])
                ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        }
        $order = CommerceOrder::query()->whereHas('invoice', fn ($query) => $query->where('id', $payment->commerce_invoice_id))->firstOrFail();
        $order = $checkout->order($request->user('customer'), $order->public_id);
        $view = $presenter->order($order, $request->user('customer'), $payment);
        $canPay = $view['canContinue'];

        if ($canPay && $payment->provider === 'zibal' && preg_match('/^[1-9][0-9]{0,18}$/D', (string) $payment->ref_id)) {
            return redirect()->away('https://gateway.zibal.ir/start/'.$payment->ref_id)
                ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
        }

        return response()->view('customer.commerce.payment', [
            'order' => $view, 'canPay' => $canPay, 'refId' => $canPay ? $payment->ref_id : null,
            'provider' => $payment->provider,
            'bankUrl' => $payment->provider === 'zibal' && $canPay && ctype_digit((string) $payment->ref_id)
                ? 'https://gateway.zibal.ir/start/'.rawurlencode($payment->ref_id) : MellatSoapClient::PAYMENT_URL,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request, string $attempt, MellatPaymentService $payments, RecurringPaymentService $recurring)
    {
        // Never ingest/store card-holder fields or rely on the customer's session or callback success alone.
        $initial = PaymentAttempt::query()->where('public_id', $attempt)->firstOrFail();
        $payload = $request->only(['RefId', 'ResCode', 'SaleOrderId', 'saleOrderId', 'SaleReferenceId']);
        $payment = CommerceInvoice::query()->findOrFail($initial->commerce_invoice_id)->commerce_order_id === null
            ? $recurring->callback($attempt, $payload) : $payments->callback($attempt, $payload);
        $result = in_array($payment->status, ['settled', 'duplicate_payment'], true) ? 'paid'
            : ($request->input('ResCode') === '0' ? 'pending' : 'incomplete');

        return response()->view('customer.commerce.payment-return', compact('result'))
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function zibalCallback(Request $request, string $attempt, ZibalPaymentService $payments, RecurringPaymentService $recurring)
    {
        $initial = PaymentAttempt::query()->where('public_id', $attempt)->firstOrFail();
        $payload = $request->only(['trackId', 'success', 'status']);
        $payment = CommerceInvoice::query()->findOrFail($initial->commerce_invoice_id)->commerce_order_id === null
            ? $recurring->callback($attempt, $payload) : $payments->callback($attempt, $payload);
        $isTest = $payment->isTestPayment();
        $result = $payment->status === 'test_succeeded' ? 'test_legacy_success' : (in_array($payment->status, ['settled', 'duplicate_payment'], true)
            ? ($isTest ? 'test_success' : 'paid')
            : (($request->input('success') === '1' || $request->input('success') === 1) ? 'pending' : 'incomplete'));

        return response()->view('customer.commerce.payment-return', compact('result', 'isTest'))
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
