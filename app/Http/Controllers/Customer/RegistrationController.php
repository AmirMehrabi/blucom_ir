<?php

namespace App\Http\Controllers\Customer;

use App\Http\Controllers\Controller;
use App\Services\CustomerRegistrationService;
use App\Support\CustomerMobile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class RegistrationController extends Controller
{
    public function __construct(private readonly CustomerRegistrationService $registrations) {}

    public function requestOtp(Request $request): JsonResponse
    {
        abort_if(Auth::guard('customer')->check(), 403);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'business' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:40'],
        ]);
        $mobile = CustomerMobile::normalize($data['mobile']);
        try {
            $challenge = $this->registrations->issue($data['name'], $mobile, $data['business']);
        } catch (ValidationException|HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            Log::error('Customer registration OTP delivery failed', ['exception_class' => $exception::class]);

            return response()->json(['message' => 'ارسال کد تأیید ممکن نشد. لطفاً کمی بعد دوباره تلاش کنید.'], 502);
        }

        $request->session()->put('customer_registration_challenge_id', $challenge->id);

        return response()->json([
            'message' => 'کد تأیید ارسال شد.', 'challenge_id' => $challenge->id,
            'cooldown_seconds' => (int) config('auth.otp.resend_cooldown_seconds'),
            'expires_seconds' => (int) config('auth.otp.expires_seconds'),
        ]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mobile' => ['required', 'string', 'max:40'], 'code' => ['required', 'digits:6'], 'challenge_id' => ['required', 'uuid'],
        ]);
        if ($data['challenge_id'] !== $request->session()->get('customer_registration_challenge_id')) {
            throw ValidationException::withMessages(['code' => 'کد واردشده معتبر نیست.']);
        }

        $customer = $this->registrations->register($data['challenge_id'], CustomerMobile::normalize($data['mobile']), $data['code']);
        Auth::guard('customer')->login($customer);
        $request->session()->regenerate();
        $request->session()->forget(['customer_registration_challenge_id', 'customer_otp_challenge_id', 'customer_otp_requested_at', 'url.intended']);

        return response()->json(['customer' => $customer->only(['id', 'name', 'mobile', 'role']), 'redirect' => '/setup/lines']);
    }
}
