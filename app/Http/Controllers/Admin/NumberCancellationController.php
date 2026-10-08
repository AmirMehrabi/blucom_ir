<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\Commerce\NumberCancellationService;
use Illuminate\Http\Request;

class NumberCancellationController extends Controller
{
    public function show(int $number)
    {
        $number = SipNumber::query()->with(['tenant', 'currentAssignment', 'inboundRoute', 'outboundRoutes'])->findOrFail($number);
        abort_unless($number->current_assignment_id !== null && in_array($number->inventory_state, ['assigned', 'quarantined'], true), 404);

        return response()->view('admin.commerce.cancel-line', [
            'number' => $number,
            'owner' => Tenant::query()->findOrFail($number->currentAssignment->tenant_id),
            'order' => CommerceOrder::query()->with('invoice')->findOrFail($number->currentAssignment->commerce_order_id),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $request, int $number, NumberCancellationService $service)
    {
        $data = $this->data($request) + $request->validate(['refund_decision' => ['required', 'in:no_refund,refund_pending'], 'confirm' => ['accepted']]);
        $service->cancel($request->user('web'), $number, (int) $data['assignment_id'], (int) $data['revision'], $data['reason'], $data['refund_decision']);

        return redirect()->route(SipNumber::query()->findOrFail($number)->inventory_state === 'quarantined' ? 'admin.numbers.cancellation' : 'admin.inventory.show', $number)->with('status', 'سرویس لغو شد؛ شماره تا تأیید آزادسازی در قرنطینه است. بازپرداخت بانکی انجام نشده است.');
    }

    public function returnToStock(Request $request, int $number, NumberCancellationService $service)
    {
        $data = $this->data($request) + $request->validate(['confirm' => ['accepted']]);
        $service->returnToStock($request->user('web'), $number, (int) $data['assignment_id'], (int) $data['revision'], $data['reason']);

        return redirect()->route('admin.inventory.show', $number)->with('status', 'شماره به موجودی برگشت؛ برای فروش دوباره تنظیمات، بررسی فنی و انتشار را انجام دهید.');
    }

    private function data(Request $request): array
    {
        return $request->validate(['assignment_id' => ['required', 'integer', 'min:1'], 'revision' => ['required', 'integer', 'min:1'], 'reason' => ['required', 'string', 'min:10', 'max:1000']]);
    }
}
