<?php

namespace App\Http\Controllers;

use App\Models\OtpChallenge;
use App\Models\User;
use App\Services\OtpService;
use App\Services\RateLimitService;
use App\Support\CustomerMobile;
use App\Support\PortalRedirect;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private readonly OtpService $otps,
        private readonly RateLimitService $rateLimits,
    ) {}

    public function requestOtp(Request $request): JsonResponse
    {
        $mobile = $this->mobile($request->validate(['mobile' => ['required', 'string', 'max:20']]));
        $user = User::query()->where('mobile', $mobile)->first();

        if (! $user) {
            throw ValidationException::withMessages(['mobile' => 'حسابی با این شماره ثبت نشده است. با مدیر تماس بگیرید.']);
        }

        if ($user?->isDisabled()) {
            throw ValidationException::withMessages(['mobile' => 'حساب کاربری غیرفعال است.']);
        }

        if ($this->rateLimits->isEnabled()
            && $request->session()->has('otp_requested_at')
            && now()->diffInSeconds($request->session()->get('otp_requested_at')) < config('auth.otp.resend_cooldown_seconds')) {
            return response()->json(['message' => 'لطفاً کمی بعد دوباره تلاش کنید.'], 429);
        }

        try {
            $challenge = $this->otps->issue($mobile, $user?->id);
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'ارسال کد تأیید ممکن نشد. لطفاً کمی بعد دوباره تلاش کنید.',
            ], 502);
        }

        $request->session()->put([
            'otp_challenge_id' => $challenge->id,
            'otp_requested_at' => now(),
            'otp_mobile' => $mobile,
        ]);

        return response()->json([
            'message' => 'کد تأیید ارسال شد.',
            'challenge_id' => $challenge->id,
            'cooldown_seconds' => (int) config('auth.otp.resend_cooldown_seconds'),
            'expires_seconds' => (int) config('auth.otp.expires_seconds'),
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'string'],
            'code' => ['required', 'digits:6'],
            'challenge_id' => ['required', 'uuid'],
        ]);
        $mobile = $this->mobile($data);
        $challenge = OtpChallenge::whereKey($data['challenge_id'])
            ->where('mobile', $mobile)
            ->where('id', $request->session()->get('otp_challenge_id'))
            ->first();

        if (! $challenge || ! $this->otps->verify($challenge, $data['code'])) {
            throw ValidationException::withMessages(['code' => 'کد واردشده معتبر نیست.']);
        }

        $user = User::query()->where('mobile', $mobile)->first();

        if (! $user || $challenge->user_id !== $user->id) {
            throw ValidationException::withMessages(['mobile' => 'اطلاعات ورود معتبر نیست.']);
        }

        if ($user?->isDisabled()) {
            throw ValidationException::withMessages(['mobile' => 'حساب کاربری غیرفعال است.']);
        }

        $user->update(['mobile_verified_at' => now()]);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->forget(['otp_challenge_id', 'otp_requested_at', 'otp_mobile']);

        return response()->json([
            'user' => $user->only(['id', 'name', 'mobile', 'user_type']),
            'redirect' => PortalRedirect::intended($request, $user->homePath()),
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(['user' => $request->user()?->only(['id', 'name', 'mobile', 'user_type'])]);
    }

    public function logout(Request $request): RedirectResponse|JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'خارج شدید.']);
        }

        return redirect()->route('login');
    }

    private function mobile(array $data): string
    {
        return CustomerMobile::normalize($data['mobile']);
    }
}
