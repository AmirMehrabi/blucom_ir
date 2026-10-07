<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Services\Commerce\PaidOrderAllocationService;
use Illuminate\Http\Request;

class OrderFulfillmentController extends Controller
{
    public function index()
    {
        return response()->view('admin.commerce.fulfillment', ['orders' => CommerceOrder::query()
            ->whereIn('status', ['paid_pending_allocation', 'paid_unfulfilled', 'allocated'])
            ->with(['item', 'invoice', 'reservation'])->latest('id')->simplePaginate(30)])
            ->header('Cache-Control', 'private, no-store');
    }

    public function repair(Request $request, int $order, PaidOrderAllocationService $allocation)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $assignment = $allocation->allocate($order, $request->user('web'), $data['reason']);

        return back()->with('status', $assignment ? 'شماره به سفارش پرداخت‌شده تخصیص یافت.' : 'شماره آزاد و آماده نیست؛ سفارش برای پیگیری محفوظ ماند.');
    }
}
