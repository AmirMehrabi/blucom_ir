<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\PaymentAttempt;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\MellatPaymentService;
use App\Services\Commerce\MellatSoapClient;
use App\Support\Permissions;
use Illuminate\Http\Request;

class PaymentController extends Controller
{
    public function initiate(Request $request, string $invoice, MellatPaymentService $payments)
    {
        $data = $request->validate(['idempotency_key' => ['required', 'uuid']]);
        $attempt = $payments->initiate($request->user('customer'), $invoice, $data['idempotency_key']);

        return redirect()->route('customer.payments.show', $attempt->public_id);
    }

    public function show(Request $request, string $attempt, CheckoutService $checkout)
    {
        $payment = PaymentAttempt::query()->where('public_id', $attempt)->firstOrFail();
        $order = CommerceOrder::query()->whereHas('invoice', fn ($query) => $query->where('id', $payment->commerce_invoice_id))->firstOrFail();
        $order = $checkout->order($request->user('customer'), $order->public_id);
        $canPay = $request->user('customer')->hasPermission(Permissions::NUMBERS_PURCHASE)
            && $request->user('customer')->hasPermission(Permissions::BILLING_MANAGE)
            && $payment->status === 'redirect_ready' && $order->status === 'reserved' && $order->expires_at->isFuture();

        return response()->view('customer.commerce.payment', [
            'status' => $payment->status, 'amount' => $order->total_amount, 'expires' => $order->expires_at,
            'canPay' => $canPay, 'refId' => $canPay ? $payment->ref_id : null, 'bankUrl' => MellatSoapClient::PAYMENT_URL,
        ])->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }

    public function callback(Request $request, string $attempt, MellatPaymentService $payments)
    {
        // Never ingest/store card-holder fields or rely on the customer's session or callback success alone.
        $payments->callback($attempt, $request->only(['RefId', 'ResCode', 'SaleOrderId', 'saleOrderId', 'SaleReferenceId']));

        return response()->view('customer.commerce.payment-return')
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
