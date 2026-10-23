<?php

namespace App\Services\OutboundCalling;

use App\Models\Customer;
use App\Models\OutboundCallSession;
use App\Models\Tenant;
use App\Services\NumberNormalizer;
use App\Services\TenantService;
use App\Support\Permissions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class InitiateTwoPartyCall
{
    public function __construct(private CallPolicyService $policy, private AudioAssetService $audio) {}

    public function handle(Customer $actor, array $input): OutboundCallSession
    {
        $input = Validator::make($input, [
            'idempotency_key' => ['required', 'uuid'], 'agent_id' => ['required', 'integer'], 'caller_id' => ['required', 'integer'],
            'customer_number' => ['required', 'string', 'max:40'], 'tenant_id' => ['prohibited'], 'gateway' => ['prohibited'],
            'agent_timeout' => ['sometimes', 'integer', 'between:5,90'], 'customer_timeout' => ['sometimes', 'integer', 'between:5,90'],
            'require_confirmation' => ['sometimes', 'boolean'], 'recording' => ['sometimes', 'boolean'],
            'agent_audio_id' => ['nullable', 'uuid'], 'customer_audio_id' => ['nullable', 'uuid'],
        ])->validate();
        abort_unless($actor->hasPermission(Permissions::OUTBOUND_INITIATE), 403);
        $tenant = app(TenantService::class)->forUser($actor);
        abort_unless($this->policy->enabled($tenant), 503, 'تماس دوطرفه هنوز فعال نشده است.');
        $number = app(NumberNormalizer::class)->normalizeOrFail($input['customer_number'], 'customer_number');
        // Hash supplied options, not changing tenant defaults, so retries remain stable.
        unset($input['tenant_id']);
        $input['customer_number'] = $number;
        ksort($input);
        $hash = hash('sha256', json_encode($input, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($actor, $tenant, $input, $number, $hash) {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            if ($existing = OutboundCallSession::query()->where('tenant_id', $tenant->id)->where('idempotency_key', $input['idempotency_key'])->first()) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'شناسه درخواست قبلاً با اطلاعات دیگری استفاده شده است.');

                return $existing;
            }
            try {
                $healthyAt = Cache::store(config('voip.live.cache_store'))->get('voip:outbound:healthy_at', 0);
            } catch (\Throwable) {
                $healthyAt = 0;
            }
            abort_unless($healthyAt >= now()->timestamp - 15, 503, 'ارتباط کنترل تماس در دسترس نیست؛ کمی بعد دوباره تلاش کنید.');
            abort_if(OutboundCallSession::query()->where('tenant_id', $tenant->id)->whereNull('cleanup_completed_at')
                ->count() >= config('voip.two_party.max_concurrent'), 429, 'ظرفیت تماس هم‌زمان تکمیل است.');
            abort_if(OutboundCallSession::query()->where('tenant_id', $tenant->id)->where('created_at', '>=', now()->subMinute())
                ->count() >= config('voip.two_party.per_minute'), 429, 'تعداد درخواست تماس بیش از حد مجاز است.');
            $route = $this->policy->route($tenant, (int) $input['agent_id'], (int) $input['caller_id']);
            $this->policy->destination($route, $number);
            $settings = array_replace($this->policy->settings($tenant), array_intersect_key($input, array_flip([
                'agent_timeout', 'customer_timeout', 'require_confirmation', 'recording', 'agent_audio_id', 'customer_audio_id',
            ])));
            abort_if($settings['recording'] && ! $this->policy->recordingAllowed($route), 422, 'ضبط خروجی برای این خط مجاز نیست.');
            $configuration = $settings + [
                'agent_audio_path' => $this->audio->path($tenant, $settings['agent_audio_id']),
                'customer_audio_path' => $this->audio->path($tenant, $settings['customer_audio_id']),
                'sip_username' => $route->sipExtension->sipIdentity(), 'gateway' => $route->gateway->name,
                'max_seconds' => (int) config('voip.two_party.max_seconds'),
                'assignment_id' => $route->sipNumber->current_assignment_id,
            ];
            $call = OutboundCallSession::query()->create([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'initiated_by_customer_id' => $actor->id,
                'sip_extension_id' => $route->sip_extension_id, 'sip_number_id' => $route->sip_number_id, 'outbound_route_id' => $route->id,
                'idempotency_key' => $input['idempotency_key'], 'request_hash' => $hash,
                'customer_number' => $number, 'caller_id' => $route->sipNumber->normalized_number,
                'agent_label' => $route->sipExtension->extension, 'configuration' => $configuration,
                'agent_uuid' => (string) Str::uuid(), 'customer_uuid' => (string) Str::uuid(), 'job_uuid' => (string) Str::uuid(),
                'deadline_at' => now()->addSeconds($configuration['max_seconds'] + 180),
            ]);
            $call->events()->create(['event_key' => 'created', 'status' => 'created', 'occurred_at' => now()]);
            Log::info('Two-party call requested', ['session_id' => $call->id, 'tenant_id' => $tenant->id, 'customer_id' => $actor->id]);

            return $call;
        });
    }
}
