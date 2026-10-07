<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentGateway;
use App\Services\Commerce\PaymentGatewayService;
use Illuminate\Http\Request;

class PaymentGatewayController extends Controller
{
    public function index()
    {
        $gateways = PaymentGateway::query()->with('currentVersion')->get()->map(fn ($gateway) => [
            'provider' => $gateway->provider, 'enabled' => $gateway->enabled, 'active' => $gateway->active, 'revision' => $gateway->revision,
            'configured' => $gateway->current_version_id !== null,
            'amount_unit_confirmed' => $gateway->currentVersion?->amount_unit_confirmed ?? false,
        ]);

        return response()->view('admin.commerce.payment-gateways', ['gateways' => $gateways])
            ->header('Cache-Control', 'no-store, private');
    }

    public function update(Request $request, string $provider, PaymentGatewayService $gateways)
    {
        $gateways->update($request->user('web'), $provider, [
            ...$request->only('revision', 'merchant_terminal_id', 'merchant_username', 'merchant_password', 'merchant_id'),
            'enabled' => $request->boolean('enabled'), 'amount_unit_confirmed' => $request->boolean('amount_unit_confirmed'),
        ]);

        return redirect()->route('admin.payment-gateways.index')->with('status', 'تنظیمات درگاه پرداخت ذخیره شد.');
    }

    public function activate(Request $request, string $provider, PaymentGatewayService $gateways)
    {
        $gateways->activate($request->user('web'), $provider);

        return redirect()->route('admin.payment-gateways.index')->with('status', 'درگاه پرداخت فعال تغییر کرد.');
    }
}
