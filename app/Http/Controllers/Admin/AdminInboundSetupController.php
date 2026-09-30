<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\InboundRoute;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\AdminVoipScope;
use App\Services\InboundAnnouncementService;
use App\Services\InboundRoutingService;
use App\Services\InboundScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AdminInboundSetupController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.time-conditions.index', [
            'routes' => InboundRoute::query()->with(['sipNumber', 'tenant', 'destination'])
                ->when($request->filled('tenant_id'), fn ($q) => $q->where('tenant_id', $request->integer('tenant_id')))
                ->when($request->filled('q'), fn ($q) => $q->whereHas('sipNumber', fn ($n) => $n->where('normalized_number', 'like', '%'.trim($request->string('q')).'%')))
                ->whereNotNull('schedule')->orderByDesc('id')->paginate(30)->withQueryString(),
            'tenants' => Tenant::query()->orderBy('name')->get(),
        ]);
    }

    public function edit(Request $request, int $sipNumber, AdminVoipScope $scope)
    {
        $number = SipNumber::query()->with(['tenant', 'inboundRoute.destination'])->findOrFail($sipNumber);
        abort_if($number->tenant === null, 404);

        return view('admin.sip-numbers.inbound', [
            'number' => $number, 'tenant' => $number->tenant, 'route' => $number->inboundRoute,
        ] + $scope->destinations($number->tenant));
    }

    public function update(Request $request, int $sipNumber, InboundRoutingService $routing, InboundAnnouncementService $announcements)
    {
        $number = SipNumber::query()->with('tenant')->findOrFail($sipNumber);
        abort_if($number->tenant === null, 404);
        abort_unless(in_array($number->status, [SipNumber::STATUS_ASSIGNED, SipNumber::STATUS_PENDING], true), 404);
        $data = $request->validate([
            'destination_choice' => ['required', 'string', 'max:40'],
            'enabled' => ['required', 'boolean'],
        ] + array_replace(InboundRoutingService::scheduleRules(), ['schedule_mode' => ['required', 'in:anytime,scheduled']]));
        $routing->destination($number->tenant, $data['destination_choice']);
        if ($data['schedule_mode'] === 'scheduled') {
            app(InboundScheduleService::class)->fromInput($data);
            if (! in_array($data['closed_action'], ['announcement', 'disconnect'], true)) {
                $routing->destination($number->tenant, $data['closed_action'], 'closed_action');
            }
        }
        if (($data['schedule_mode'] ?? '') === 'scheduled' && ($data['closed_action'] ?? '') === 'announcement' && $request->hasFile('announcement')) {
            $data['announcement_path'] = $announcements->store($number, $request->file('announcement'));
        }
        $routing->configure($number->tenant, $number, $data);

        return redirect()->route('admin.sip-numbers.setup', ['sip_number' => $number->id, 'tab' => 'inbound'])
            ->with('status', 'مقصد تماس و زمان‌بندی ذخیره شد.');
    }

    public function preview(Request $request, int $sipNumber, InboundScheduleService $schedules)
    {
        $number = SipNumber::query()->with('inboundRoute.destination')->findOrFail($sipNumber);
        $data = $request->validate(['at' => ['required', 'date_format:Y-m-d\TH:i']]);
        $route = $number->inboundRoute;
        abort_if($route === null, 404);
        $at = CarbonImmutable::createFromFormat('!Y-m-d\TH:i', $data['at'], $route->schedule['timezone'] ?? 'Asia/Tehran');
        $open = $schedules->isOpen($route, $at);
        $holiday = in_array($at->toDateString(), $route->schedule['closed_dates'] ?? [], true);

        return back()->with('schedule_preview', [
            'at' => $at->format('Y-m-d H:i T'), 'open' => $open,
            'reason' => $holiday ? 'تعطیلی ویژه' : ($open ? 'ساعات باز' : 'خارج از ساعات کاری'),
            'destination' => $open ? $route->destinationLabel() : $route->closedDestinationLabel(),
        ]);
    }

    public function announcement(int $sipNumber)
    {
        $number = SipNumber::query()->findOrFail($sipNumber);
        $path = $number->inboundRoute?->closed_announcement_path;
        abort_unless(is_string($path) && preg_match('#^announcements/'.$number->tenant_id.'/'.$number->id.'/[0-9A-Z]+\.wav$#D', $path)
            && Storage::disk('ivr')->exists($path), 404);

        return response()->file(Storage::disk('ivr')->path($path), ['Content-Type' => 'audio/wav', 'Cache-Control' => 'private, no-store']);
    }
}
