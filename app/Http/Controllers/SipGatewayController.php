<?php

namespace App\Http\Controllers;

use App\Models\SipGateway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SipGatewayController extends Controller
{
    public function index(): View
    {
        return view('sip-gateways.index', [
            'mode' => 'admin',
            'gateways' => SipGateway::query()->orderBy('name')->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_-]+$/', 'unique:sip_gateways,name'],
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'min:1', 'max:65535'],
            'transport' => ['required', 'in:udp,tcp,tls'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255'],
            'profile' => ['required', 'in:external,internal'],
            'context' => ['required', 'in:public,default'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        SipGateway::query()->create([
            'name' => $data['name'],
            'host' => $data['host'],
            'port' => $data['port'],
            'transport' => $data['transport'],
            'username' => $data['username'] ?? null,
            'password_encrypted' => $data['password'] ?? null,
            'profile' => $data['profile'],
            'context' => $data['context'],
            'enabled' => (bool) $request->boolean('enabled', true),
        ]);

        return back()->with('status', 'دروازه SIP ثبت شد.');
    }

    public function update(Request $request, int $sipGateway): RedirectResponse
    {
        $gateway = SipGateway::query()->findOrFail($sipGateway);

        $data = $request->validate([
            'host' => ['sometimes', 'string', 'max:255'],
            'port' => ['sometimes', 'integer', 'min:1', 'max:65535'],
            'transport' => ['sometimes', 'in:udp,tcp,tls'],
            'username' => ['nullable', 'string', 'max:100'],
            'password' => ['nullable', 'string', 'max:255'],
            'profile' => ['sometimes', 'in:external,internal'],
            'context' => ['sometimes', 'in:public,default'],
            'enabled' => ['sometimes', 'boolean'],
        ]);

        $password = $data['password'] ?? null;
        $gateway->update(collect($data)->except('password')->all());

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
}
