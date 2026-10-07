<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerOtpChallenge;
use App\Services\CustomerOtpService;
use App\Services\RateLimitService;
use App\Services\TenantService;
use App\Support\CustomerMobile;
use App\Support\PortalRedirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly CustomerOtpService $otps, private readonly TenantService $tenants) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $mobile = $this->mobile($request->validate(['mobile' => ['required', 'string', 'max:20']]));
        $customer = Customer::query()->where('mobile', $mobile)->first();
        if ($customer === null) {
            throw ValidationException::withMessages(['mobile' => 'حساب مشتری ثبت نشده است. از صفحه ثبت‌نام حساب بسازید.']);
        }
        $this->tenants->forUser($customer);
        if (app(RateLimitService::class)->isEnabled() && $request->session()->has('customer_otp_requested_at')
            && now()->diffInSeconds($request->session()->get('customer_otp_requested_at')) < config('auth.otp.resend_cooldown_seconds')) {
            return response()->json(['message' => 'لطفاً کمی بعد دوباره تلاش کنید.'], 429);
        }
        try {
            $challenge = $this->otps->issue($customer);
        } catch (\Throwable $exception) {
            Log::error('Customer OTP delivery failed', ['exception_class' => $exception::class]);

            return response()->json(['message' => 'ارسال کد تأیید ممکن نشد.'], 502);
        }
        $request->session()->put(['customer_otp_challenge_id' => $challenge->id, 'customer_otp_requested_at' => now()]);

        return response()->json([
            'message' => 'کد تأیید ارسال شد.', 'challenge_id' => $challenge->id,
            'cooldown_seconds' => (int) config('auth.otp.resend_cooldown_seconds'),
            'expires_seconds' => (int) config('auth.otp.expires_seconds'),
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:20'], 'code' => ['required', 'digits:6'], 'challenge_id' => ['required', 'uuid'],
        ]);
        $customer = Customer::query()->where('mobile', $this->mobile($data))->first();
        $challenge = CustomerOtpChallenge::query()->whereKey($data['challenge_id'])
            ->where('id', $request->session()->get('customer_otp_challenge_id'))
            ->where('customer_id', $customer?->id)->where('mobile', $customer?->mobile)->first();
        if ($customer === null || $challenge === null) {
            throw ValidationException::withMessages(['code' => 'کد واردشده معتبر نیست.']);
        }
        $this->tenants->forUser($customer);
        if (! $this->otps->verify($challenge, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'کد واردشده معتبر نیست.']);
        }
        $customer->update(['mobile_verified_at' => now()]);
        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();
        $request->session()->forget(['customer_otp_challenge_id', 'customer_otp_requested_at']);

        return response()->json(['customer' => $customer->only(['id', 'name', 'mobile', 'role']), 'redirect' => PortalRedirect::intended($request, $customer->homePath())]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['customer' => $request->user('customer')->only(['id', 'name', 'mobile', 'role'])]);
    }

    public function logout(Request $request): RedirectResponse|JsonResponse
    {
        Auth::guard('customer')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return $request->expectsJson() ? response()->json(['message' => 'خارج شدید.']) : redirect()->route('customer.login');
    }

    private function mobile(array $data): string
    {
        return CustomerMobile::normalize($data['mobile']);
    }
}
