<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class QueueAvailabilityController extends Controller
{
    public function index(Request $request): View
    {
        return view('call-queues.availability', ['extension' => $request->user()->sipExtension]);
    }

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['Available', 'On Break'])]]);
        $extension = $request->user()->sipExtension;
        if ($extension === null || ! $extension->enabled || $extension->tenant_id !== $request->user()->tenant_id) {
            throw ValidationException::withMessages(['status' => 'ابتدا از مدیر بخواهید داخلی شما را به حساب کاربری‌تان وصل کند.']);
        }
        $extension->update(['queue_status' => $data['status']]);

        if ($request->expectsJson()) {
            return response()->json(['status' => $extension->queue_status]);
        }

        return back()->with('status', $data['status'] === 'Available' ? 'آماده پاسخ‌گویی هستید.' : 'وضعیت استراحت فعال شد.');
    }
}
