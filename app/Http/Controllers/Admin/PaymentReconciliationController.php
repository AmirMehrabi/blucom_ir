<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommerceInvoice;
use App\Models\PaymentAttempt;
use App\Services\Commerce\MellatPaymentService;
use Illuminate\Http\Request;

class PaymentReconciliationController extends Controller
{
    public function index(Request $request)
    {
        $filter = $request->validate(['status' => ['nullable', 'in:initiating,redirect_ready,verifying,settling,reversing,unknown,pending_settlement,settled,duplicate_payment,reversed,initiation_failed']]);
        $attempts = PaymentAttempt::query()->when($filter['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->latest('id')->paginate(30)->withQueryString();
        $invoices = CommerceInvoice::query()->whereIn('id', $attempts->pluck('commerce_invoice_id'))->get()->keyBy('id');

        return response()->view('admin.commerce.payments', compact('attempts', 'invoices'))->header('Cache-Control', 'no-store, private');
    }

    public function reconcile(Request $request, int $attempt, MellatPaymentService $payments)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $result = $payments->reconcile($request->user('web'), $attempt, $data['reason']);

        return redirect()->route('admin.payments.index')->with('status', 'بررسی بانکی انجام شد. وضعیت: '.$result->statusLabel());
    }

    public function reverse(Request $request, int $attempt, MellatPaymentService $payments)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:1000']]);
        $result = $payments->reverse($request->user('web'), $attempt, $data['reason']);

        return redirect()->route('admin.payments.index')->with('status', 'بررسی برگشت انجام شد. وضعیت: '.$result->statusLabel());
    }
}
