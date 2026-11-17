<?php

namespace App\Services\AudioCalls;

use App\Models\AudioCall;
use App\Models\Tenant;
use App\Models\TenantAudioAsset;
use App\Services\NumberNormalizer;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RequestAudioCall
{
    public function __construct(private AudioCallPolicy $policy, private NumberNormalizer $numbers) {}

    public function handle(Tenant $tenant, array $data, string $key, ?int $customerId, ?int $keyId): AudioCall
    {
        $destination = $this->numbers->normalizeOrFail($data['to'], 'to');
        $lineId = isset($data['caller_id_id']) ? (int) $data['caller_id_id'] : null;
        $audioId = $data['audio_id'] ?? null;
        $url = $data['audio_url'] ?? null;
        $requestHash = hash('sha256', json_encode([$destination, $lineId, $audioId, $url], JSON_THROW_ON_ERROR));
        $requestKey = hash('sha256', ($customerId === null ? 'api' : 'customer-'.$customerId).':'.$key);

        return DB::transaction(function () use ($tenant, $destination, $lineId, $audioId, $url, $requestHash, $requestKey, $customerId, $keyId) {
            Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $calls = AudioCall::query()->where('tenant_id', $tenant->id);
            $existing = (clone $calls)->where('request_key', $requestKey)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $requestHash), 409, 'Idempotency key already used for a different request.');

                return $existing;
            }
            abort_unless(config('audio_calls.enabled'), 403, 'Audio calling is disabled.');
            $line = $this->policy->line($tenant, $lineId, $destination);
            if ($audioId && ! TenantAudioAsset::query()->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereKey($audioId)->exists()) {
                throw ValidationException::withMessages(['audio_id' => 'Select an available audio clip.']);
            }
            abort_if((clone $calls)->whereNull('finished_at')->count() >= 2
                || (clone $calls)->where('created_at', '>=', now()->subMinute())->count() >= 5, 429, 'Audio call limit reached.');
            $heartbeat = Cache::store(config('voip.live.cache_store'))->get('voip:outbound:healthy_at');
            abort_unless($heartbeat && $heartbeat >= now()->timestamp - 20, 503, 'Call worker unavailable.');

            return AudioCall::query()->create([
                'id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'customer_id' => $customerId, 'key_id' => $keyId,
                'caller_id_id' => $line->id, 'assignment_id' => $line->current_assignment_id, 'caller_id' => $line->normalized_number,
                'destination' => $destination, 'audio_id' => $audioId, 'audio_url' => $url,
                'request_key' => $requestKey, 'request_hash' => $requestHash, 'status' => $url ? 'preparing_audio' : 'queued',
            ]);
        });
    }
}
