<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\CustomerCommercePresenter;
use App\Services\Commerce\MellatPaymentService;
use App\Services\Commerce\MellatSoapClient;
use App\Services\Commerce\ZibalPaymentService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function initiate(Request $request, string $invoice, MellatPaymentService $mellat, ZibalPaymentService $zibal)
    {
        try {
            $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);
            $provider = PaymentGateway::query()->where('active', true)->value('provider');
            abort_unless(in_array($provider, ['mellat', 'zibal'], true), 404);
            $attempt = $provider === 'zibal'
                ? $zibal->initiate($request->user('customer'), $invoice, $data['idempotency_key'])
                : $mellat->initiate($request->user('customer'), $invoice, $data['idempotency_key']);
        } catch (ValidationException) {
            return redirect()->route('customer.orders.index')->withErrors(['payment' => 'پرداخت این سفارش در حال حاضر امکان‌پذیر نیست. وضعیت سفارش و مهلت رزرو را بررسی کنید یا با پشتیبانی تماس بگیرید.']);
        }

        return redirect()->route('customer.payments.show', $attempt->public_id);
    }

    public function show(Request $request, string $attempt, CheckoutService $checkout, CustomerCommercePresenter $presenter)
    {
        $payment = PaymentAttempt::query()->where('public_id', $attempt)->firstOrFail();
        $order = CommerceOrder::query()->whereHas('invoice', fn ($query) => $query->where('id', $payment->commerce_invoice_id))->firstOrFail();
        $order = $checkout->order($request->user('customer'), $order->public_id);
        $view = $presenter->order($order, $request->user('customer'), $payment);
        $canPay = $view['canContinue'];

        return response()->view('customer.commerce.payment', [
            'order' => $view, 'canPay' => $canPay, 'refId' => $canPay ? $payment->ref_id : null,
            'provider' => $payment->provider,
            'bankUrl' => $payment->provider === 'zibal' && $canPay && ctype_digit((string) $payment->ref_id)
                ? 'https://gateway.zibal.ir/start/'.rawurlencode($payment->ref_id) : MellatSoapClient::PAYMENT_URL,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request, string $attempt, MellatPaymentService $payments)
    {
        // Never ingest/store card-holder fields or rely on the customer's session or callback success alone.
        $payment = $payments->callback($attempt, $request->only(['RefId', 'ResCode', 'SaleOrderId', 'saleOrderId', 'SaleReferenceId']));
        $result = in_array($payment->status, ['settled', 'duplicate_payment'], true) ? 'paid'
            : ($request->input('ResCode') === '0' ? 'pending' : 'incomplete');

        return response()->view('customer.commerce.payment-return', compact('result'))
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function zibalCallback(Request $request, string $attempt, ZibalPaymentService $payments)
    {
        $payment = $payments->callback($attempt, $request->only(['trackId', 'success', 'status']));
        $result = in_array($payment->status, ['settled', 'duplicate_payment'], true) ? 'paid'
            : (($request->input('success') === '1' || $request->input('success') === 1) ? 'pending' : 'incomplete');

        return response()->view('customer.commerce.payment-return', compact('result'))
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
