<?php

namespace App\Http\Controllers;

use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Services\NumberNormalizer;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PhonePortalController extends Controller
{
    public function loginPage(Request $request): Response|RedirectResponse
    {
        if ($request->session()->has('phone_extension_id')) {
            return redirect()->route('phone.dashboard');
        }

        return response()->view('phone.login')->header('Cache-Control', 'no-store, private');
    }

    public function login(Request $request, NumberNormalizer $numbers): RedirectResponse
    {
        $data = $request->validate([
            'line_number' => ['required', 'string', 'max:32'],
            'username' => ['required', 'string', 'max:64'],
            'password' => ['required', 'string', 'max:128'],
        ]);

        $normalized = $numbers->normalize($data['line_number']);
        $extension = SipExtension::query()
            ->where('sip_username', $data['username'])
            ->orWhere(fn ($query) => $query->whereNull('sip_username')->where('extension', $data['username']))
            ->with('tenant')
            ->first();

        $line = $normalized === null ? null : SipNumber::query()
            ->where('normalized_number', $normalized)
            ->where('tenant_id', $extension?->tenant_id)
            ->where('status', SipNumber::STATUS_ASSIGNED)
            ->where('enabled', true)
            ->first();

        try {
            $storedPassword = $extension?->password_encrypted;
        } catch (DecryptException) {
            $storedPassword = null;
        }
        $passwordMatches = is_string($storedPassword) && hash_equals($storedPassword, $data['password']);

        if (! $passwordMatches || ! $extension->enabled || $extension->tenant?->status !== 'active'
            || $extension->tenant?->system_key !== null || $line === null) {
            throw ValidationException::withMessages(['credentials' => 'شماره خط یا اطلاعات ورود درست نیست.']);
        }

        $request->session()->regenerate();
        $request->session()->put(['phone_extension_id' => $extension->id, 'phone_number_id' => $line->id]);

        return redirect()->route('phone.dashboard');
    }

    public function dashboard(Request $request): Response
    {
        [$extension, $number] = $this->identity($request);

        return response()->view('phone.dashboard', [
            'extension' => $extension,
            'number' => $number,
            'sampleCalls' => [
                ['name' => 'مریم احمدی', 'number' => '۰۹۱۲ ۳۴۵ ۶۷۸۹', 'time' => '۱۰:۴۲', 'duration' => '۰۴:۳۲', 'type' => 'incoming', 'status' => 'answered'],
                ['name' => 'پشتیبانی آریا', 'number' => '۰۲۱ ۸۸۷۷ ۴۴۳۳', 'time' => '۰۹:۱۸', 'duration' => '۰۲:۱۵', 'type' => 'outgoing', 'status' => 'answered'],
                ['name' => 'تماس ورودی', 'number' => '۰۹۳۵ ۶۷۸ ۱۲۳۴', 'time' => 'دیروز', 'duration' => '—', 'type' => 'incoming', 'status' => 'missed'],
            ],
        ])->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget(['phone_extension_id', 'phone_number_id']);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('phone.login');
    }

    /** @return array{SipExtension, SipNumber} */
    public function identity(Request $request): array
    {
        $extension = SipExtension::query()->with('tenant')->find($request->session()->get('phone_extension_id'));
        $number = SipNumber::query()->find($request->session()->get('phone_number_id'));

        abort_unless($extension && $number && $extension->enabled
            && $extension->tenant_id === $number->tenant_id
            && $extension->tenant?->isActive()
            && $number->isRoutable() && $number->enabled, 403);

        return [$extension, $number];
    }
}
