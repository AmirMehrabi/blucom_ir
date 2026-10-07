<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\Commerce\CheckoutService;
use App\Services\Commerce\CustomerCommercePresenter;
use App\Services\Commerce\NumberReservationService;
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

        return $this->page('customer.commerce.catalog', ['offers' => $offers, 'enabled' => $enabled]);
    }

    public function review(Request $request, int $offer, CheckoutService $checkout, CustomerCommercePresenter $presenter)
    {
        try {
            $quote = $checkout->quote($request->user('customer'), $offer);
        } catch (ValidationException) {
            return redirect()->route('customer.numbers.index')->withErrors(['checkout' => 'این شماره دیگر برای خرید در دسترس نیست. لطفاً شمارهٔ دیگری انتخاب کنید.']);
        }

        return $this->page('customer.commerce.review', [
            'selection' => $presenter->quote($quote), 'quote' => $quote, 'key' => (string) Str::uuid(),
            'canReserve' => config('commerce.reservation_enabled') && $presenter->checkoutReady() && $presenter->canPurchase($request->user('customer')),
            'minutes' => CustomerCommercePresenter::digits((int) config('commerce.reservation_minutes')),
            ...$presenter->paymentMode(),
        ]);
    }

    public function reserve(Request $request, CheckoutService $checkout, CustomerCommercePresenter $presenter)
    {
        abort_unless($presenter->canPurchase($request->user('customer')), 403);
        abort_unless($presenter->checkoutReady(), 409);
        try {
            $data = $request->validate([
                'offer_id' => ['required', 'integer', 'min:1'], 'plan_version_id' => ['required', 'integer', 'min:1'],
                'monthly_amount' => ['required', 'integer', 'min:1', 'max:1000000000000'], 'currency' => ['required', 'in:IRT'],
                'idempotency_key' => ['required', 'uuid'], 'confirmed' => ['accepted'],
            ], ['confirmed.accepted' => 'برای ادامه، مبلغ و جزئیات سفارش را تأیید کنید.']);
            $order = $checkout->reserve($request->user('customer'), (int) $data['offer_id'], $data, $data['idempotency_key']);
        } catch (ValidationException $failure) {
            $message = $failure->validator->errors()->has('confirmed')
                ? 'برای ادامه، مبلغ و جزئیات سفارش را تأیید کنید.'
                : 'شماره یا قیمت انتخاب‌شده تغییر کرده است. لطفاً جزئیات خرید را دوباره بررسی کنید.';

            return redirect()->route('customer.numbers.index')->withErrors(['checkout' => $message]);
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

    private function page(string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'no-store, private')->header('Referrer-Policy', 'no-referrer');
    }
}
