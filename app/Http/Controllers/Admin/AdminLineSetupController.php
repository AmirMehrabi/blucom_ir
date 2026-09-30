<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminLineSetup;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Services\AdminLineSetupService;
use App\Services\AdminVoipScope;
use App\Services\BlucomOwner;
use App\Services\InboundRoutingService;
use App\Services\InboundScheduleService;
use App\Services\NumberNormalizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AdminLineSetupController extends Controller
{
    public function index(Request $request)
    {
        return view('admin.setup.index', [
            'tenants' => Tenant::query()->where('status', 'active')->orderBy('name')->get(),
            'defaultTenantId' => $request->integer('tenant_id') ?: app(BlucomOwner::class)->get()->id,
            'drafts' => AdminLineSetup::query()->where('created_by_user_id', $request->user()->id)
                ->whereNull('completed_at')->with('tenant')->latest()->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['tenant_id' => ['required', 'integer', Rule::exists('tenants', 'id')->where('status', 'active')]]);
        $draft = AdminLineSetup::query()->create($data + ['created_by_user_id' => $request->user()->id, 'data' => []]);

        return redirect()->route('admin.setup.show', $draft);
    }

    public function show(Request $request, int $setup, AdminVoipScope $scope, AdminLineSetupService $service)
    {
        $draft = $this->draft($request, $setup);
        if ($draft->completed_at) {
            return redirect()->route('admin.sip-numbers.setup', $draft->sip_number_id);
        }
        $tenant = $draft->tenant;
        $step = min(max(2, $request->integer('step', $draft->step)), $draft->step);
        $number = ($draft->data['number_id'] ?? null) ? $tenant->sipNumbers()->with('inboundRoute')->find($draft->data['number_id']) : null;
        $destinations = $scope->destinations($tenant);
        $labels = [];
        foreach ($destinations['extensions'] as $extension) {
            $labels['extension:'.$extension->id] = $extension->display_name ?: 'داخلی '.$extension->extension;
        }
        foreach ($destinations['queues'] as $queue) {
            $labels['queue:'.$queue->id] = 'تیم '.$queue->name;
        }
        foreach ($destinations['menus'] as $menu) {
            $labels['ivr:'.$menu->id] = 'منوی '.$menu->name;
        }

        return view('admin.setup.wizard', [
            'draft' => $draft, 'tenant' => $tenant, 'data' => $draft->data ?? [], 'step' => $step, 'number' => $number,
            'gateways' => $service->gateways($tenant)->orderBy('name')->get(),
            'numbers' => $tenant->sipNumbers()->whereIn('status', [SipNumber::STATUS_ASSIGNED, SipNumber::STATUS_PENDING])
                ->where('enabled', true)->where('inbound_enabled', true)->orderBy('normalized_number')->get(),
            'outboundAssignments' => $service->outboundSnapshot($tenant, $draft->data['outbound_extension_ids'] ?? []),
            'destinationLabels' => $labels,
        ] + $destinations);
    }

    public function update(Request $request, int $setup, AdminLineSetupService $service, InboundRoutingService $routing)
    {
        $draft = $this->draft($request, $setup);
        abort_if($draft->completed_at, 409);
        $step = $request->validate(['step' => ['required', 'integer', 'between:2,5']])['step'];
        abort_if($step > $draft->step, 422);
        $tenant = $draft->tenant;
        $data = $draft->data ?? [];
        if ($step === 2) {
            $input = $request->validate(['gateway_id' => ['required', 'integer']]);
            $gateway = $service->gateways($tenant)->find($input['gateway_id']);
            if ($gateway === null) {
                throw ValidationException::withMessages(['gateway_id' => 'اتصال باید فعال و متعلق به همین مالک یا زیرساخت بلوکام باشد.']);
            }
            if (($data['gateway_id'] ?? null) !== $gateway->id) {
                $data = ['gateway_id' => $gateway->id];
            }
        } elseif ($step === 3) {
            $input = $request->validate([
                'number_mode' => ['required', 'in:new,existing'], 'number_id' => ['required_if:number_mode,existing', 'nullable', 'integer'],
                'number' => ['required_if:number_mode,new', 'nullable', 'string', 'max:32'], 'label' => ['nullable', 'string', 'max:100'],
            ]);
            $data = ['gateway_id' => $data['gateway_id']] + $input;
            if ($input['number_mode'] === 'existing') {
                $number = $tenant->sipNumbers()->where('enabled', true)->where('inbound_enabled', true)
                    ->whereIn('status', [SipNumber::STATUS_ASSIGNED, SipNumber::STATUS_PENDING])->findOrFail($input['number_id']);
                $route = $number->inboundRoute;
                $data['number_fingerprint'] = $service->fingerprint($number);
                $data['label'] = $input['label'] ?? $number->label;
                $data['answerer'] = $route ? 'existing' : 'new';
                $data['destination_choice'] = $route ? $route->destination_type.':'.$route->destination_id : null;
                $data['schedule_mode'] = $route?->schedule ? 'scheduled' : 'anytime';
                $data['timezone'] = $route?->schedule['timezone'] ?? 'Asia/Tehran';
                $data['weekly'] = $route?->schedule['weekly'] ?? [];
                $formatter = new \IntlDateFormatter('fa_IR@calendar=persian', 0, 0, 'Asia/Tehran', \IntlDateFormatter::TRADITIONAL, 'yyyy/MM/dd');
                $data['closed_dates'] = array_map(fn ($date) => $formatter->format(new \DateTimeImmutable($date.' 12:00:00', new \DateTimeZone('Asia/Tehran'))), $route?->schedule['closed_dates'] ?? []);
                $data['closed_action'] = $route?->closed_destination_type
                    ? (in_array($route->closed_destination_type, ['announcement', 'disconnect']) ? $route->closed_destination_type : $route->closed_destination_type.':'.$route->closed_destination_id) : 'disconnect';
            } else {
                $normalized = app(NumberNormalizer::class)->normalizeOrFail($input['number']);
                validator(['number' => $normalized], ['number' => ['unique:sip_numbers,normalized_number']])->validate();
            }
        } elseif ($step === 4) {
            $input = $request->validate([
                'answerer' => ['required', 'in:new,existing'], 'display_name' => ['required_if:answerer,new', 'nullable', 'string', 'max:100'],
                'destination_choice' => ['required_if:answerer,existing', 'nullable', 'string', 'max:40'],
            ] + array_replace(InboundRoutingService::scheduleRules(), ['schedule_mode' => ['required', 'in:anytime,scheduled']]));
            if ($input['answerer'] === 'existing') {
                $routing->destination($tenant, $input['destination_choice']);
            }
            if (($input['schedule_mode'] ?? '') === 'scheduled') {
                app(InboundScheduleService::class)->fromInput($input);
                if (! in_array($input['closed_action'], ['announcement', 'disconnect'])) {
                    $routing->destination($tenant, $input['closed_action'], 'closed_action');
                }
                if ($input['closed_action'] === 'announcement' && ! $request->hasFile('announcement') && ! $draft->announcement_upload
                    && ! ($data['number_id'] ?? null ? $tenant->sipNumbers()->find($data['number_id'])?->inboundRoute?->closed_announcement_path : null)) {
                    throw ValidationException::withMessages(['announcement' => 'فایل پیام ساعات بسته را بارگذاری کنید.']);
                }
                if ($request->hasFile('announcement')) {
                    $path = $request->file('announcement')->storeAs('wizard-uploads/'.$draft->id, Str::ulid().'.upload', 'ivr');
                    if ($draft->announcement_upload) {
                        Storage::disk('ivr')->delete($draft->announcement_upload);
                    }
                    $draft->announcement_upload = $path;
                }
            }
            if (($input['schedule_mode'] ?? '') !== 'scheduled' || ($input['closed_action'] ?? '') !== 'announcement') {
                if ($draft->announcement_upload) {
                    Storage::disk('ivr')->delete($draft->announcement_upload);
                    $draft->announcement_upload = null;
                }
            }
            unset($input['announcement']);
            $data = array_replace($data, $input);
            unset($data['outbound_snapshot']);
        } else {
            $input = $request->validate([
                'outbound_enabled' => ['required', 'boolean'], 'include_new_extension' => ['sometimes', 'boolean'],
                'outbound_extension_ids' => ['nullable', 'array'],
                'outbound_extension_ids.*' => ['integer', 'distinct', Rule::exists('sip_extensions', 'id')->where('tenant_id', $tenant->id)->where('enabled', true)],
            ]);
            $input['outbound_enabled'] = $request->boolean('outbound_enabled');
            $input['outbound_extension_ids'] = array_map('intval', $input['outbound_extension_ids'] ?? []);
            $input['include_new_extension'] = $data['answerer'] === 'new' && $request->boolean('include_new_extension');
            if ($input['outbound_enabled']) {
                $gateway = $service->gateways($tenant)->find($data['gateway_id']);
                if (! $gateway?->approved_for_outbound || ($input['outbound_extension_ids'] === [] && ! $input['include_new_extension'])) {
                    throw ValidationException::withMessages(['outbound_extension_ids' => 'اتصال مجاز و دست‌کم یک داخلی برای خروجی انتخاب کنید.']);
                }
            } else {
                $input['outbound_extension_ids'] = [];
                $input['include_new_extension'] = false;
            }
            $input['outbound_snapshot'] = $service->outboundSnapshot($tenant, $input['outbound_extension_ids']);
            $data = array_replace($data, $input);
        }
        $draft->fill(['data' => $data, 'step' => $step + 1])->save();

        return redirect()->route('admin.setup.show', $draft)->with('status', 'پیش‌نویس ذخیره شد؛ تنظیمات تماس هنوز تغییر نکرده است.');
    }

    public function finish(Request $request, int $setup, AdminLineSetupService $service)
    {
        $request->validate(['confirm' => ['accepted']]);
        $result = $service->finish($this->draft($request, $setup));

        return redirect()->route('admin.sip-numbers.setup', $result['number'])
            ->with('phone_credentials', $result['credentials'])->with('status', 'راه‌اندازی ذخیره شد. ثبت تلفن و تماس واقعی را آزمایش کنید.');
    }

    public function destroy(Request $request, int $setup)
    {
        $draft = $this->draft($request, $setup);
        abort_if($draft->completed_at, 409);
        if ($draft->announcement_upload && preg_match('#^wizard-uploads/'.$draft->id.'/[0-9A-Z]+\.upload$#D', $draft->announcement_upload)) {
            Storage::disk('ivr')->delete($draft->announcement_upload);
        }
        $draft->delete();

        return redirect()->route('admin.setup.index')->with('status', 'پیش‌نویس حذف شد. تنظیمات تماس تغییری نکرد.');
    }

    private function draft(Request $request, int $id): AdminLineSetup
    {
        return AdminLineSetup::query()->where('created_by_user_id', $request->user()->id)->findOrFail($id);
    }
}
