<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CommerceOrder;
use App\Services\Commerce\NumberReservationService;
use Illuminate\Http\Request;

class ReservationController extends Controller
{
    public function index(Request $request)
    {
        $data = $request->validate(['status' => ['nullable', 'in:held,cancelled,expired,allocated']]);
        $status = $data['status'] ?? 'held';
        $orders = CommerceOrder::query()->whereHas('reservation', fn ($query) => $query->where('status', $status))
            ->with(['item', 'invoice', 'reservation'])->latest('id')->paginate(30)->withQueryString();

        return response()->view('admin.commerce.reservations', compact('orders', 'status'))
            ->header('Cache-Control', 'private, no-store');
    }

    public function cancel(Request $request, string $order, NumberReservationService $reservations)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1000']]);
        $result = $reservations->cancel($request->user('web'), $order, $data['reason']);

        return back()->with('status', $result->status === 'cancelled'
            ? 'رزرو لغو شد و شماره آزاد شد.' : 'شماره آزاد شد؛ سابقه پرداخت برای بررسی و پیگیری حفظ شد.');
    }
}
