<?php

namespace App\Http\Controllers;

use App\Models\SipGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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
        ]);

        return back()->with('status', 'دروازه SIP ثبت شد.');
    }

    public function update(Request $request, int $sipGateway): RedirectResponse
    {
        $gateway = SipGateway::query()->findOrFail($sipGateway);
        $data = $this->validated($request, false, $gateway);

        $payload = [
            'host' => $data['host'] ?? $gateway->host,
            'port' => $data['port'] ?? $gateway->port,
            'transport' => $data['transport'] ?? $gateway->transport,
            'username' => $data['username'] ?? null,
            'profile' => 'external',
            'context' => 'public',
            'enabled' => (bool) $request->boolean('enabled', $gateway->enabled),
        ];

        if (array_key_exists('name', $data)) {
            $payload['name'] = $data['name'];
        }

        $gateway->update($payload);

        $password = $data['password'] ?? null;

        if ($password !== null && $password !== '') {
            $gateway->update(['password_encrypted' => $password]);
        }

        return back()->with('status', 'دروازه SIP به‌روزرسانی شد.');
    }

    public function destroy(int $sipGateway): RedirectResponse
    {
        SipGateway::query()->findOrFail($sipGateway)->delete();

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
                'host' => ['required', 'string', 'max:255'],
                'port' => ['required', 'integer', 'min:1', 'max:65535'],
                'transport' => ['required', 'in:udp,tcp,tls'],
                'username' => ['nullable', 'string', 'max:100'],
                'password' => ['nullable', 'string', 'max:255'],
            ]);
        }

        return $request->validate([
            'name' => ['sometimes', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/', Rule::unique('sip_gateways', 'name')->ignore($gateway?->id)],
            'host' => ['sometimes', 'string', 'max:255'],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'transport' => ['sometimes', 'in:udp,tcp,tls'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255'],
        ]);
    }
}
