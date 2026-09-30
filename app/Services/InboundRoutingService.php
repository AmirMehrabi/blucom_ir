<?php

namespace App\Services;

use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class InboundRoutingService
{
    public static function scheduleRules(): array
    {
        return [
            'schedule_mode' => ['sometimes', 'required', 'in:anytime,scheduled'],
            'timezone' => ['required_if:schedule_mode,scheduled', 'nullable', 'timezone'],
            'weekly' => ['nullable', 'array'],
            'weekly.*' => ['array'],
            'weekly.*.*.start' => ['nullable', 'string'],
            'weekly.*.*.end' => ['nullable', 'string'],
            'closed_dates' => ['nullable', 'array', 'max:30'],
            'closed_dates.*' => ['nullable', 'string', 'max:12'],
            'closed_action' => ['required_if:schedule_mode,scheduled', 'nullable', 'string', 'max:40'],
            'announcement' => ['nullable', 'file', 'max:10240', 'mimetypes:audio/wav,audio/x-wav,audio/mpeg,audio/mp4,audio/x-m4a,audio/webm,video/webm,application/octet-stream'],
        ];
    }

    public function destination(Tenant $tenant, string $choice, string $field = 'destination_choice'): SipExtension|CallQueue|IvrMenu
    {
        if (! preg_match('/^(extension|queue|ivr):([1-9][0-9]*)$/D', $choice, $matches)) {
            throw ValidationException::withMessages([$field => 'یک مقصد معتبر انتخاب کنید.']);
        }
        $destination = match ($matches[1]) {
            'extension' => SipExtension::query()->whereBelongsTo($tenant)->where('enabled', true)->find($matches[2]),
            'queue' => config('voip.queues_enabled')
                ? CallQueue::query()->whereBelongsTo($tenant)->where('enabled', true)
                    ->whereHas('members', fn ($query) => $query->where('enabled', true))->find($matches[2]) : null,
            'ivr' => IvrMenu::query()->whereBelongsTo($tenant)->where('enabled', true)
                ->whereNotNull('published_config')->find($matches[2]),
        };
        if ($destination === null) {
            throw ValidationException::withMessages([$field => 'مقصد باید فعال و متعلق به همین مالک باشد.']);
        }

        return $destination;
    }

    public function scheduleSettings(Tenant $tenant, SipNumber $number, array $data): array
    {
        abort_unless($number->tenant_id === $tenant->id, 404);
        if (($data['schedule_mode'] ?? 'anytime') !== 'scheduled') {
            return ['schedule' => null, 'closed_destination_type' => null, 'closed_destination_id' => null,
                'closed_announcement_path' => null];
        }
        $schedule = app(InboundScheduleService::class)->fromInput($data);
        $choice = (string) ($data['closed_action'] ?? '');
        $path = null;
        $id = null;
        if ($choice === 'announcement') {
            $path = $data['announcement_path'] ?? $number->inboundRoute?->closed_announcement_path;
            if (! is_string($path)
                || ! preg_match('#^announcements/'.$tenant->id.'/'.$number->id.'/[0-9A-Z]+\.wav$#D', $path)
                || ! Storage::disk('ivr')->exists($path)) {
                throw ValidationException::withMessages(['announcement' => 'برای پیام پایان تماس، فایل صوتی بارگذاری کنید.']);
            }
            $type = 'announcement';
        } elseif ($choice === 'disconnect') {
            $type = 'disconnect';
        } else {
            $destination = $this->destination($tenant, $choice, 'closed_action');
            [$type] = explode(':', $choice);
            $id = $destination->id;
        }

        return ['schedule' => $schedule, 'closed_destination_type' => $type,
            'closed_destination_id' => $id, 'closed_announcement_path' => $path];
    }

    public function configure(Tenant $tenant, SipNumber $number, array $data): InboundRoute
    {
        abort_unless($number->tenant_id === $tenant->id, 404);
        $destination = $this->destination($tenant, $data['destination_choice']);
        [$type] = explode(':', $data['destination_choice']);
        $settings = array_key_exists('schedule_mode', $data) ? $this->scheduleSettings($tenant, $number, $data) : [];
        $route = InboundRoute::query()->updateOrCreate(['sip_number_id' => $number->id], [
            'tenant_id' => $tenant->id, 'destination_type' => $type, 'destination_id' => $destination->id,
            'enabled' => (bool) ($data['enabled'] ?? true),
        ] + $settings);
        Log::info('Inbound routing configured', ['tenant_id' => $tenant->id, 'sip_number_id' => $number->id, 'route_id' => $route->id]);

        return $route;
    }
}
