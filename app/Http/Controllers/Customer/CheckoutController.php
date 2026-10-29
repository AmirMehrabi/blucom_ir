<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\NumberReservation;
use App\Models\PlanVersion;
use App\Models\SipNumber;
use App\Models\TenantSubscription;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\CustomerCommercePresenter;
use App\Services\Commerce\FulfillmentRequestService;
use App\Services\Commerce\NumberReservationService;
use App\Services\Commerce\RecurringPaymentService;
use App\Services\Commerce\WalletService;
use App\Support\Permissions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CheckoutController extends Controller
{
    public function index(Request $request, CheckoutService $checkout, CustomerCommercePresenter $presenter)
    {
        abort_unless($request->user('customer')->hasPermission(Permissions::NUMBERS_PURCHASE), 403);
        $enabled = (bool) config('commerce.catalog_enabled');
        $offers = $enabled ? $checkout->catalog($request->user('customer')) : null;
        if ($offers !== null) {
            $offers->setCollection($offers->getCollection()->map(fn ($quote) => $presenter->quote($quote)));
        }
        $subscription = config('commerce.separated_billing_enabled')
            ? TenantSubscription::query()->with('planVersion')->where('tenant_id', $request->user('customer')->tenant_id)->first() : null;
        $usedNumbers = $subscription ? SipNumber::query()->where('tenant_id', $subscription->tenant_id)
            ->where('inventory_state', 'assigned')->count()
            + NumberReservation::query()->where('tenant_id', $subscription->tenant_id)->where('status', 'held')
                ->where('expires_at', '>', now())->count() : 0;
        $numberLimit = (int) ($subscription?->planVersion?->limits['numbers'] ?? 0);

        return $this->page('customer.commerce.catalog', compact('offers', 'enabled', 'subscription', 'usedNumbers', 'numberLimit'));
    }

    public function review(Request $request, int $offer, CheckoutService $checkout, CustomerCommercePresenter $presenter)
    {
        $selectedPlan = $request->integer('plan') ?: null;
        try {
            $quote = $checkout->quote($request->user('customer'), $offer, $selectedPlan);
        } catch (ValidationException $exception) {
            $message = $exception->errors()['checkout'][0] ?? '';

            return redirect()->route('customer.numbers.index')->withErrors(['checkout' => str_contains($message, 'ظرفیت')
                ? $message : 'این شماره دیگر برای خرید در دسترس نیست. لطفاً شمارهٔ دیگری انتخاب کنید.']);
        }

        $walletPurchase = config('commerce.wallet_enabled') && $quote['billing_model'] === 'one_time_number';
        $walletBalance = $walletPurchase ? app(WalletService::class)->balance($request->user('customer')->tenant_id) : 0;
        $paymentMode = $presenter->paymentMode();
        $paymentReady = $walletPurchase
            ? config('commerce.checkout_enabled') && ($walletBalance >= $quote['monthly_amount'] || ($presenter->checkoutReady() && ! $paymentMode['isTest']))
            : $presenter->checkoutReady();

        return $this->page('customer.commerce.review', [
            'selection' => $presenter->quote($quote), 'quote' => $quote, 'key' => (string) Str::uuid(),
            'planChoices' => config('commerce.separated_billing_enabled')
                && ! TenantSubscription::query()->where('tenant_id', $request->user('customer')->tenant_id)->exists()
                ? PlanVersion::query()->with('plan')->whereNotNull('published_at')->whereNotNull('monthly_price')
                    ->whereHas('plan', fn ($query) => $query->where('archived', false))->orderBy('monthly_price')->get()
                : collect(),
            'offerId' => $offer,
            'walletBalance' => $walletBalance,
            'walletFundingIsTest' => $walletPurchase && $paymentMode['isTest'],
            'canReserve' => config('commerce.reservation_enabled') && $paymentReady && $presenter->canPurchase($request->user('customer')),
            'minutes' => CustomerCommercePresenter::digits((int) config('commerce.reservation_minutes')),
            ...$paymentMode,
            'isTest' => ! $walletPurchase && $paymentMode['isTest'],
        ]);
    }

    public function reserve(Request $request, CheckoutService $checkout, CustomerCommercePresenter $presenter)
    {
        abort_unless($presenter->canPurchase($request->user('customer')), 403);
        abort_unless($presenter->checkoutReady() || config('commerce.wallet_enabled'), 409);
        try {
            $data = $request->validate([
                'offer_id' => ['required', 'integer', 'min:1'], 'plan_version_id' => ['required', 'integer', 'min:1'],
                'monthly_amount' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'currency' => ['required', 'in:IRT'],
                'idempotency_key' => ['required', 'uuid'], 'confirmed' => ['accepted'],
            ], ['confirmed.accepted' => 'برای ادامه، مبلغ و جزئیات سفارش را تأیید کنید.']);
            $order = $checkout->reserve($request->user('customer'), (int) $data['offer_id'], $data, $data['idempotency_key']);
        } catch (ValidationException $failure) {
            $capacity = $failure->errors()['checkout'][0] ?? '';
            $message = $failure->validator->errors()->has('confirmed')
                ? 'برای ادامه، مبلغ و جزئیات سفارش را تأیید کنید.'
                : (str_contains($capacity, 'ظرفیت') ? $capacity
                    : 'شماره یا قیمت انتخاب‌شده تغییر کرده است. لطفاً جزئیات خرید را دوباره بررسی کنید.');

            return redirect()->route('customer.numbers.index')->withErrors(['checkout' => $message]);
        }

        if (config('commerce.wallet_enabled') && $order->item->billing_model === 'one_time_number') {
            try {
                $funding = app(WalletService::class)->prepare($request->user('customer'), $order->invoice->public_id, $data['idempotency_key']);
                if ($funding) {
                    $attempt = app(RecurringPaymentService::class)->initiate($request->user('customer'), $funding->public_id, $data['idempotency_key'], false);

                    return redirect()->route('customer.payments.show', $attempt->public_id);
                }
            } catch (ValidationException $exception) {
                return redirect()->route('customer.orders.show', $order->public_id)->withErrors($exception->errors());
            }
        }

        return redirect()->route('customer.orders.show', $order->public_id);
    }

    public function orders(Request $request, CheckoutService $checkout, CustomerCommercePresenter $presenter)
    {
        $orders = $checkout->orders($request->user('customer'));
        $orders->setCollection($orders->getCollection()->map(fn ($order) => $presenter->order($order, $request->user('customer'))));

        return $this->page('customer.commerce.orders', compact('orders'));
    }

    public function show(Request $request, string $order, CheckoutService $checkout, CustomerCommercePresenter $presenter)
    {
        $order = $presenter->order($checkout->order($request->user('customer'), $order), $request->user('customer'));

        return $this->page('customer.commerce.order', ['order' => $order, 'key' => (string) Str::uuid()]);
    }

    public function cancel(Request $request, string $order, NumberReservationService $reservations)
    {
        try {
            $result = $reservations->cancel($request->user('customer'), $order);
        } catch (ValidationException $exception) {
            return redirect()->route('customer.orders.show', $order)->withErrors($exception->errors());
        }

        return redirect()->route('customer.orders.show', $order)->with('status', $result->status === 'cancelled'
            ? 'رزرو لغو شد و شماره آزاد شد.' : 'رزرو لغو شد؛ اگر پرداختی شروع کرده‌اید، نتیجه آن را با پشتیبانی پیگیری کنید.');
    }

    public function requestUnready(Request $request, string $order, FulfillmentRequestService $service)
    {
        $data = $request->validate(['choice' => ['required', 'in:refund,replacement']]);
        try {
            $service->request($request->user('customer'), $order, $data['choice']);
        } catch (ValidationException $exception) {
            return redirect()->route('customer.orders.show', $order)->withErrors($exception->errors());
        }

        return redirect()->route('customer.orders.show', $order)
            ->with('status', 'درخواست شما ثبت شد. پشتیبانی نتیجه بررسی و روش انجام را با شما هماهنگ می‌کند؛ پرداخت مجدد لازم نیست.');
    }

    private function page(string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
