<?php

namespace App\Http\Controllers;

use App\Models\SipExtension;
use App\Services\ExtensionAvailabilityService;
use App\Services\TenantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class QueueAvailabilityController extends Controller
{
    public function index(Request $request): View
    {
        $tenant = app(TenantService::class)->forUser($request->user());
        $extension = $request->user()->sipExtension;

        return view('call-queues.availability', ['extension' => $extension?->tenant_id === $tenant->id ? $extension->loadCount(['callQueues' => fn ($query) => $query->where('enabled', true)]) : null]);
    }

    public function update(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['Available', 'On Break'])]]);
        $extension = $request->user()->sipExtension;
        if ($extension === null || ! $extension->enabled || $extension->tenant_id !== $request->user()->tenant_id) {
            throw ValidationException::withMessages(['status' => 'ابتدا از مدیر بخواهید داخلی شما را به حساب کاربری‌تان وصل کند.']);
        }
        DB::transaction(function () use ($extension, $data, $request) {
            $actor = $request->user()->newQuery()->lockForUpdate()->findOrFail($request->user()->id);
            abort_unless($actor->hasPermission('queues.work') && $actor->sip_extension_id === $extension->id && $actor->tenant_id === $extension->tenant_id, 403);
            $locked = SipExtension::query()->lockForUpdate()->findOrFail($extension->id);
            if ($locked->queue_status !== $data['status']) {
                $locked->queue_status = $data['status'];
                $locked->availability_version++;
                $locked->save();
                app(ExtensionAvailabilityService::class)->record($request->user(), $locked, $data['status'] === 'Available' ? 'break_ended' : 'break_started');
            }
            $extension->setRawAttributes($locked->getAttributes(), true);
        });

        if ($request->expectsJson()) {
            return response()->json(['status' => $extension->queue_status, 'paused' => $extension->paused_at !== null, 'version' => $extension->availability_version]);
        }

        return back()->with('status', $data['status'] === 'Available' ? ($extension->paused_at ? 'استراحت تیمی پایان یافت؛ توقف دریافت تماس همچنان فعال است.' : 'آماده پاسخ‌گویی هستید.') : 'وضعیت استراحت فعال شد.');
    }
}
