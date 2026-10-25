<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingFulfillmentRequest;
use App\Models\CommerceOrder;
use App\Models\NumberTerm;
use App\Models\Tenant;
use App\Services\Commerce\PaidOrderAllocationService;
use Illuminate\Http\Request;

class OrderFulfillmentController extends Controller
{
    public function index(Request $request)
    {
        return response()->view('admin.commerce.fulfillment', [
            'contextTenant' => Tenant::query()->find($request->integer('tenant_id')),
            'orders' => CommerceOrder::query()->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))->when($request->filled('order_id'), fn ($q) => $q->whereKey($request->integer('order_id')))->whereIn('status', ['paid_pending_allocation', 'paid_unfulfilled', 'allocated'])
                ->with(['item', 'invoice', 'reservation'])->latest('id')->simplePaginate(30)->withQueryString(),
            'unreadyRequests' => BillingFulfillmentRequest::query()->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))->where('status', 'open')
                ->with(['order.invoice', 'customer'])->latest('id')->limit(50)->get(),
            'reviewTerms' => NumberTerm::query()->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))->where('status', 'active')
                ->where('release_review_at', '<=', now())
                ->whereHas('assignment', fn ($query) => $query->whereNull('released_at'))
                ->with('assignment.sipNumber')->orderBy('release_review_at')->limit(50)->get(),
        ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function repair(Request $request, int $order, PaidOrderAllocationService $allocation)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $assignment = $allocation->allocate($order, $request->user('web'), $data['reason']);

        return back()->with('status', $assignment ? 'شماره به سفارش پرداخت‌شده تخصیص یافت.' : 'شماره آزاد و آماده نیست؛ سفارش برای پیگیری محفوظ ماند.');
    }
}
