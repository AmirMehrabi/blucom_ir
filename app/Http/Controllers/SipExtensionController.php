<?php

namespace App\Http\Controllers;

use App\Models\SipExtension;
use App\Services\TenantService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class SipExtensionController extends Controller
{
    public function __construct(private readonly TenantService $tenants) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenants->forUser($request->user());

        return view('sip-extensions.index', [
            'mode' => 'customer',
            'extensions' => SipExtension::query()
                ->whereBelongsTo($tenant)
                ->orderBy('extension')
                ->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());

        $data = $request->validate([
            'extension' => ['required', 'string', 'regex:/^[1-9]\d{2,8}$/', 'max:10'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'min:8', 'max:64'],
        ]);

        if (SipExtension::query()->where('extension', $data['extension'])->exists()) {
            return back()->withErrors(['extension' => 'این داخلی قبلاً ثبت شده است.'])->withInput();
        }

        $password = $data['password'] ?? null;
        $password = ($password !== null && $password !== '') ? $password : Str::random(16);

        SipExtension::query()->create([
            'tenant_id' => $tenant->id,
            'extension' => $data['extension'],
            'password_encrypted' => $password,
            'display_name' => $data['display_name'] ?? null,
            'enabled' => true,
        ]);

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

    public function update(Request $request, int $sipExtension): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        $extension = SipExtension::query()->whereBelongsTo($tenant)->findOrFail($sipExtension);

        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'display_name' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'min:8', 'max:64'],
        ]);

        $password = $data['password'] ?? null;
        unset($data['password']);

        $extension->update($data);

        $credentials = null;

        if ($password !== null && $password !== '') {
            $extension->update(['password_encrypted' => $password]);
            $credentials = [
                'extension' => $extension->extension,
                'password' => $password,
            ];
        }

        return back()->with(array_filter([
            'status' => 'داخلی SIP به‌روزرسانی شد.',
            'extension_credentials' => $credentials,
        ]));
    }

    public function destroy(Request $request, int $sipExtension): RedirectResponse
    {
        $tenant = $this->tenants->forUser($request->user());
        SipExtension::query()->whereBelongsTo($tenant)->findOrFail($sipExtension)->delete();

        return redirect()->route('sip-extensions.index')->with('status', 'داخلی SIP حذف شد.');
    }
}
