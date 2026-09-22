<?php

namespace App\Http\Controllers;

use App\Enums\UserType;
use App\Models\{OtpChallenge, User};
use App\Services\OtpService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(private readonly OtpService $otps) {}

    public function requestOtp(Request $request)
    {
        $type = $this->type($request);
        $mobile = $this->mobile($request->validate(['mobile' => ['required', 'string', 'max:20']]));
        $user = User::where('mobile', $mobile)->first();
        if ($type === UserType::Admin && $user?->user_type !== UserType::Admin) return response()->json(['message' => 'اگر اطلاعات صحیح باشد، کد ارسال خواهد شد.']);
        if ($request->session()->has('otp_requested_at') && now()->diffInSeconds($request->session()->get('otp_requested_at')) < config('auth.otp.resend_cooldown_seconds')) return response()->json(['message' => 'لطفاً کمی بعد دوباره تلاش کنید.'], 429);
        $challenge = $this->otps->issue($mobile, $user?->id);
        $request->session()->put(['otp_challenge_id' => $challenge->id, 'otp_requested_at' => now()]);
        return response()->json(['message' => 'کد تأیید ارسال شد.', 'challenge_id' => $challenge->id]);
    }

    public function verify(Request $request)
    {
        $type = $this->type($request);
        $data = $request->validate(['mobile' => ['required', 'string'], 'code' => ['required', 'digits:6'], 'challenge_id' => ['required', 'uuid']]);
        $mobile = $this->mobile($data);
        $challenge = OtpChallenge::whereKey($data['challenge_id'])->where('mobile', $mobile)->first();
        if (! $challenge || ! $this->otps->verify($challenge, $data['code'])) throw ValidationException::withMessages(['code' => 'کد واردشده معتبر نیست.']);
        $user = User::where('mobile', $mobile)->first();
        if ($type === UserType::Admin && $user?->user_type !== UserType::Admin) throw ValidationException::withMessages(['mobile' => 'اطلاعات ورود معتبر نیست.']);
        $user ??= User::create(['mobile' => $mobile, 'user_type' => UserType::Customer, 'mobile_verified_at' => now(), 'name' => 'کاربر']);
        $user->update(['mobile_verified_at' => now()]);
        Auth::login($user); $request->session()->regenerate();
        return response()->json(['user' => $user->only(['id', 'name', 'mobile', 'user_type'])]);
    }

    public function me(Request $request) { return response()->json(['user' => $request->user()?->only(['id', 'name', 'mobile', 'user_type'])]); }
    public function logout(Request $request) { Auth::logout(); $request->session()->invalidate(); $request->session()->regenerateToken(); return response()->json(['message' => 'خارج شدید.']); }
    private function type(Request $request): UserType { return str_contains($request->getHost(), 'admin.') ? UserType::Admin : UserType::Customer; }
    private function mobile(array $data): string { $value = preg_replace('/[\s\-()]/', '', $data['mobile']); if (str_starts_with($value, '09')) $value = '+98'.substr($value, 1); elseif (str_starts_with($value, '989')) $value = '+'.$value; if (! preg_match('/^\+989\d{9}$/', $value)) throw ValidationException::withMessages(['mobile' => 'شماره موبایل معتبر نیست.']); return $value; }
}
