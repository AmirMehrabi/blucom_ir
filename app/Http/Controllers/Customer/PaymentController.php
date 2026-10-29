<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\CommerceInvoice;
use App\Models\CommerceOrder;
use App\Models\PaymentAttempt;
use App\Models\PaymentGateway;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\CustomerCommercePresenter;
use App\Services\Commerce\MellatPaymentService;
use App\Services\Commerce\MellatSoapClient;
use App\Services\Commerce\RecurringPaymentService;
use App\Services\Commerce\WalletService;
use App\Services\Commerce\ZibalPaymentService;
use App\Services\Portal\CustomerBillingSummary;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentController extends Controller
{
    public function initiate(Request $request, string $invoice, MellatPaymentService $mellat, ZibalPaymentService $zibal, RecurringPaymentService $recurring)
    {
        $isRecurring = CommerceInvoice::query()->where('public_id', $invoice)->whereNull('commerce_order_id')->exists();
        try {
            $data = $request->validate(['idempotency_key' => ['required', 'uuid'], 'payment_mode' => ['sometimes', 'required', 'in:live,test']]);
            $record = CommerceInvoice::query()->where('public_id', $invoice)->where('tenant_id', $request->user('customer')->tenant_id)->firstOrFail();
            if (config('commerce.wallet_enabled') && (in_array($record->kind, ['plan_renewal', 'plan_upgrade'], true)
                || ($record->commerce_order_id && CommerceOrder::query()->findOrFail($record->commerce_order_id)->item->billing_model === 'one_time_number'))) {
                return redirect()->route('customer.billing.invoice', $record->public_id)
                    ->with('status', 'موجودی و کسری مبلغ را بررسی کنید و پرداخت را از کیف پول ادامه دهید.');
            }
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
        } catch (ValidationException $exception) {
            $record = CommerceInvoice::query()->where('public_id', $invoice)->where('tenant_id', $request->user('customer')->tenant_id)->firstOrFail();

            return redirect()->route('customer.billing.invoice', $record->public_id)->withErrors($exception->errors());
        }

        return redirect()->route('customer.payments.show', $attempt->public_id);
    }

    public function show(Request $request, string $attempt, CheckoutService $checkout, CustomerCommercePresenter $presenter)
    {
        $payment = PaymentAttempt::query()->where('public_id', $attempt)->firstOrFail();
        $invoice = CommerceInvoice::query()->findOrFail($payment->commerce_invoice_id);
        if ($invoice->commerce_order_id === null) {
            abort_unless($invoice->tenant_id === $request->user('customer')->tenant_id
                && $request->user('customer')->hasPermission(Permissions::BILLING_VIEW), 404);
            $canPay = $payment->status === 'redirect_ready' && $invoice->status === 'issued' && $invoice->expires_at?->isFuture()
                && $request->user('customer')->hasPermission(Permissions::BILLING_MANAGE);
            if ($canPay && $payment->provider === 'zibal' && preg_match('/^[1-9][0-9]{0,18}$/D', (string) $payment->ref_id)) {
                return redirect()->away('https://gateway.zibal.ir/start/'.$payment->ref_id)
                    ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
            }

            return response()->view('customer.billing.payment', ['invoice' => $invoice, 'payment' => $payment,
                'canPay' => $canPay, 'invoiceAction' => app(CustomerBillingSummary::class)->action($invoice, $request->user('customer')), 'bankUrl' => MellatSoapClient::PAYMENT_URL])
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

    public function returnFromBank(Request $request, string $attempt, CheckoutService $checkout, CustomerCommercePresenter $presenter)
    {
        $customer = $request->user('customer');
        abort_unless($customer->hasPermission(Permissions::BILLING_VIEW), 403);
        $payment = PaymentAttempt::query()->where('public_id', $attempt)->firstOrFail();
        $invoice = CommerceInvoice::query()->where('tenant_id', $customer->tenant_id)->findOrFail($payment->commerce_invoice_id);
        if ($invoice->kind === 'wallet_topup' && $invoice->hasValidSettlement()) {
            app(WalletService::class)->applyTopup($invoice->id);
            app(WalletService::class)->recoverDue();
            $target = CommerceInvoice::query()->where('tenant_id', $customer->tenant_id)->find($invoice->wallet_target_invoice_id);
            $url = $target?->commerce_order_id
                ? route('customer.orders.show', CommerceOrder::query()->findOrFail($target->commerce_order_id)->public_id)
                : ($target ? route('customer.billing.invoice', $target->public_id) : route('customer.billing.index'));

            return redirect()->to($url)->with('status', $target && $target->fresh()->status !== 'paid'
                ? 'شارژ کیف پول تأیید شد؛ خرید تکمیل نشد و مبلغ در کیف پول شما محفوظ است. وضعیت خرید را بررسی کنید یا شماره دیگری انتخاب کنید.'
                : 'شارژ کیف پول تأیید شد و موجودی و وضعیت خرید به‌روز هستند.')->header('Cache-Control', 'private, no-store');
        }
        if ($invoice->commerce_order_id === null) {
            return redirect()->route('customer.billing.invoice', $invoice->public_id)->header('Cache-Control', 'private, no-store');
        }
        $record = CommerceOrder::query()->findOrFail($invoice->commerce_order_id);
        $order = $presenter->order($checkout->order($customer, $record->public_id), $customer);

        return redirect()->to($customer->hasPermission(Permissions::LINES_VIEW) && $order['setupUrl']
            ? $order['setupUrl'] : route('customer.orders.show', $record->public_id))->header('Cache-Control', 'private, no-store');
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

        return response()->view('customer.commerce.payment-return', compact('result') + ['continueUrl' => route('customer.payments.return', $payment->public_id)])
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

        return response()->view('customer.commerce.payment-return', compact('result', 'isTest') + ['continueUrl' => route('customer.payments.return', $payment->public_id)])
            ->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
