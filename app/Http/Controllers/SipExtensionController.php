<?php

namespace App\Http\Controllers;

use App\Http\Requests\Admin\ExtensionRequest;
use App\Models\InboundRoute;
use App\Models\SipExtension;
use App\Services\BlucomOwner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class SipExtensionController extends Controller
{
    public function __construct(private readonly BlucomOwner $owner) {}

    public function index(Request $request): Response
    {
        $tenant = $this->owner->get();

        return response()->view('sip-extensions.index', [
            'mode' => 'admin',
            'extensions' => SipExtension::query()
                ->whereBelongsTo($tenant)
                ->orderBy('extension')
                ->get(),
        ], 200, ['Cache-Control' => 'no-store']);
    }

    public function store(ExtensionRequest $request): RedirectResponse
    {
        $tenant = $this->owner->get();

        $data = $request->validated();

        if (SipExtension::query()->where('extension', $data['extension'])->exists()) {
            return back()->withErrors(['extension' => 'این داخلی قبلاً ثبت شده است.'])->withInput();
        }

        $password = $data['password'] ?? null;
        $password = ($password !== null && $password !== '') ? $password : Str::random(16);

        $extension = SipExtension::query()->create([
            'tenant_id' => $tenant->id,
            'extension' => $data['extension'],
            'password_encrypted' => $password,
            'display_name' => $data['display_name'] ?? null,
            'enabled' => true,
        ]);
        Log::info('SIP extension created', ['extension_id' => $extension->id]);

        return back()->with([
            'status' => 'داخلی SIP ثبت شد.',
            'extension_credentials' => [
                'extension' => $data['extension'],
                'password' => $password,
                'host' => (string) config('voip.sip_host', parse_url(config('app.url'), PHP_URL_HOST)),
                'port' => (int) config('voip.sip_port', 5060),
            ],
        ]);
    }

    public function update(ExtensionRequest $request, int $sipExtension): RedirectResponse
    {
        $tenant = $this->owner->get();
        $extension = SipExtension::query()->whereBelongsTo($tenant)->findOrFail($sipExtension);

        $data = $request->validated();

        if (($data['generate_password'] ?? false) && ! empty($data['password'])) {
            return back()->withErrors(['password' => 'رمز دستی و تولید خودکار را همزمان انتخاب نکنید.']);
        }

        $password = ($data['generate_password'] ?? false) ? Str::random(20) : ($data['password'] ?? null);
        unset($data['password'], $data['generate_password']);

        $extension->update($data);

        $credentials = null;

        if ($password !== null && $password !== '') {
            $extension->update(['password_encrypted' => $password]);
            $credentials = [
                'extension' => $extension->extension,
                'password' => $password,
                'host' => (string) config('voip.sip_host'),
                'port' => (int) config('voip.sip_port', 5060),
            ];
        }
        Log::info('SIP extension updated', ['extension_id' => $extension->id]);

        return back()->with(array_filter([
            'status' => 'داخلی SIP به‌روزرسانی شد.',
            'extension_credentials' => $credentials,
        ]));
    }

    public function destroy(Request $request, int $sipExtension): RedirectResponse
    {
        $tenant = $this->owner->get();
        $extension = SipExtension::query()->whereBelongsTo($tenant)->findOrFail($sipExtension);

        if (InboundRoute::query()->where('destination_type', InboundRoute::DESTINATION_EXTENSION)->where('destination_id', $extension->id)->exists() || $extension->outboundRoute()->exists()) {
            return back()->withErrors(['extension' => 'ابتدا مسیرهای وابسته به این داخلی را حذف کنید.']);
        }

        $extension->delete();
        Log::info('SIP extension deleted', ['extension_id' => $extension->id]);

        return redirect()->route('sip-extensions.index')->with('status', 'داخلی SIP حذف شد.');
    }
}
