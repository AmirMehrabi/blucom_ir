<?php

namespace App\Http\Middleware;

use App\Models\SipNumber;
use App\Models\TenantSubscription;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureBillingWritable
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->getHost() !== config('portal.customer_domain') || $request->isMethodSafe()
            || ! $request->user('customer')) {
            return $next($request);
        }
        if ($request->is('start/existing', 'billing/*', 'billing', 'invoices/*/payments', 'payments/*', 'auth/logout', 'orders', 'orders/*/cancel', 'orders/*/unready-request', 'outbound/two-party/*/cancel')) {
            return $next($request);
        }
        $subscription = TenantSubscription::query()->where('tenant_id', $request->user('customer')->tenant_id)->first();
        if ($subscription && ($subscription->status !== 'active' || ! $subscription->grace_ends_at?->isFuture()
            || ($subscription->cancel_at && $subscription->cancel_at->isPast()))) {
            return redirect()->route('customer.billing.index')->withErrors([
                'billing' => 'اشتراک ماهانه فعال نیست. سوابق و صورتحساب‌ها در دسترس‌اند؛ پس از پرداخت تماس‌ها و تغییر تنظیمات دوباره فعال می‌شوند.',
            ]);
        }
        if (config('commerce.separated_billing_enabled') && ! $subscription
            && ! SipNumber::query()->where('tenant_id', $request->user('customer')->tenant_id)
                ->where('inventory_state', 'assigned')->exists()) {
            return redirect()->route('customer.numbers.index')->withErrors([
                'billing' => 'برای شروع تنظیمات و تماس‌ها، ابتدا یک پلن ماهانه و شماره انتخاب کنید.',
            ]);
        }

        return $next($request);
    }
}
