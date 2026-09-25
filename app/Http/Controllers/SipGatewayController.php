<?php

namespace App\Http\Controllers;

use App\Models\SipGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class SipGatewayController extends Controller
{
    public function index(Request $request): View
    {
        $editId = $request->query('edit');

        return view('sip-gateways.index', [
            'mode' => 'admin',
            'gateways' => SipGateway::query()->orderBy('name')->get(),
            'editing' => $editId ? SipGateway::query()->find($editId) : null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request, true);
        $this->validateRegistration($request->boolean('register'), $data['username'] ?? null, $data['password'] ?? null);

        SipGateway::query()->create([
            'name' => $data['name'],
            'host' => $data['host'],
            'port' => $data['port'],
            'transport' => $data['transport'],
            'username' => $data['username'] ?? null,
            'password_encrypted' => $data['password'] ?? null,
            'profile' => 'external',
            'context' => 'public',
            'enabled' => (bool) $request->boolean('enabled', true),
            'register' => (bool) $request->boolean('register'),
            'auth_username' => $data['auth_username'] ?? null,
            'realm' => $data['realm'] ?? null,
            'approved_for_outbound' => (bool) $request->boolean('approved_for_outbound'),
        ]);

        Log::info('SIP gateway created', ['gateway_name' => $data['name']]);

        return back()->with('status', 'دروازه SIP ثبت شد.');
    }

    public function update(Request $request, int $sipGateway): RedirectResponse
    {
        $gateway = SipGateway::query()->findOrFail($sipGateway);
        $data = $this->validated($request, false, $gateway);
        $this->validateRegistration(
            $request->exists('register') ? $request->boolean('register') : $gateway->register,
            array_key_exists('username', $data) ? $data['username'] : $gateway->username,
            ($data['password'] ?? null) ?: $gateway->password_encrypted,
        );

        $payload = [
            'host' => $data['host'] ?? $gateway->host,
            'port' => $data['port'] ?? $gateway->port,
            'transport' => $data['transport'] ?? $gateway->transport,
            'profile' => 'external',
            'context' => 'public',
        ];

        foreach (['username', 'auth_username', 'realm'] as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }
        foreach (['enabled', 'register', 'approved_for_outbound'] as $field) {
            if ($request->exists($field)) {
                $payload[$field] = $request->boolean($field);
            }
        }

        if (array_key_exists('name', $data)) {
            $payload['name'] = $data['name'];
        }

        $gateway->update($payload);

        $password = $data['password'] ?? null;

        if ($password !== null && $password !== '') {
            $gateway->update(['password_encrypted' => $password]);
        }

        Log::info('SIP gateway updated', ['gateway_id' => $gateway->id]);

        return back()->with('status', 'دروازه SIP به‌روزرسانی شد.');
    }

    public function destroy(int $sipGateway): RedirectResponse
    {
        $gateway = SipGateway::query()->findOrFail($sipGateway);
        if ($gateway->outboundRoutes()->exists() || $gateway->sipNumbers()->exists()) {
            return back()->withErrors(['gateway' => 'ابتدا مسیرها و شماره‌های وابسته را جدا کنید.']);
        }
        $gateway->delete();
        Log::info('SIP gateway deleted', ['gateway_id' => $gateway->id]);

        return redirect()->route('sip-gateways.index')->with('status', 'دروازه SIP حذف شد.');
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $isCreate, ?SipGateway $gateway = null): array
    {
        if ($isCreate) {
            return $request->validate([
                'name' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/', 'unique:sip_gateways,name'],
                'host' => ['required', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.\-]+$/'],
                'auth_username' => ['nullable', 'string', 'max:100'],
                'realm' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.\-]+$/'],
                'port' => ['required', 'integer', 'min:1', 'max:65535'],
                'transport' => ['required', 'in:udp,tcp,tls'],
                'username' => ['nullable', 'string', 'max:100'],
                'password' => ['nullable', 'string', 'max:255'],
                'register' => ['sometimes', 'boolean'],
                'approved_for_outbound' => ['sometimes', 'boolean'],
            ]);
        }

        return $request->validate([
            'name' => ['sometimes', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/', Rule::unique('sip_gateways', 'name')->ignore($gateway?->id)],
            'host' => ['sometimes', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.\-]+$/'],
            'auth_username' => ['nullable', 'string', 'max:100'],
            'realm' => ['nullable', 'string', 'max:255', 'regex:/^[a-zA-Z0-9.\-]+$/'],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'transport' => ['sometimes', 'in:udp,tcp,tls'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255'],
            'register' => ['sometimes', 'boolean'],
            'approved_for_outbound' => ['sometimes', 'boolean'],
        ]);
    }

    private function validateRegistration(bool $register, ?string $username, ?string $password): void
    {
        if ($register && ($username === null || $username === '' || $password === null || $password === '')) {
            throw ValidationException::withMessages(['register' => 'برای ثبت نزد ارائه‌دهنده، نام کاربری و رمز عبور لازم است.']);
        }
    }
}
