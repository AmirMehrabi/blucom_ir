<?php

namespace Tests\Feature;

use App\Models\AudioCall;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Services\AudioCalls\AudioCallWorker;
use App\Services\CustomerAccountService;
use App\Services\OutboundCalling\SwitchCallControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AudioCallsTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'https://my.blucom.ir/api/v1/audio-calls';

    public function test_documentation_is_linked_from_home_and_customer_navigation(): void
    {
        $owner = app(CustomerAccountService::class)->createOwner('Owner', '+989120000011', 'Business');
        $this->get('https://blucom.ir/')->assertOk()->assertSee('/developers');
        $this->get('https://blucom.ir/developers')->assertOk()->assertSee('Idempotency-Key')->assertSee('CURLOPT_POSTFIELDS');
        $this->actingAs($owner, 'customer')->get('https://my.blucom.ir/outbound/audio-calls')->assertOk()->assertSee('/developers');
    }

    public function test_api_authentication_idempotency_tenant_boundaries_and_audio_failure(): void
    {
        $owner = app(CustomerAccountService::class)->createOwner('Owner', '+989120000011', 'Business');
        $other = app(CustomerAccountService::class)->createOwner('Other', '+989120000012', 'Other');
        $gateway = SipGateway::factory()->create(['approved_for_outbound' => true, 'verification_status' => 'approved']);
        $line = SipNumber::factory()->create(['tenant_id' => $owner->tenant_id, 'provider_gateway_id' => $gateway->id]);
        $spool = storage_path('framework/testing/audio-calls');
        File::ensureDirectoryExists($spool);
        config([
            'audio_calls.enabled' => true, 'audio_calls.line_ids' => [$line->id],
            'voip.two_party.spool' => $spool, 'voip.live.cache_store' => 'array',
        ]);
        Cache::put('voip:outbound:healthy_at', now()->timestamp, 20);
        $token = $this->key($owner->tenant_id);
        $otherToken = $this->key($other->tenant_id);
        $headers = ['Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'order-1042'];
        $data = ['to' => '+989121234567', 'audio_url' => 'https://127.0.0.1/secret.wav'];
        $this->postJson(self::API, $data)->assertUnauthorized();
        $created = $this->postJson(self::API, $data, $headers)->assertAccepted()
            ->assertJsonPath('status', 'preparing_audio')->assertJsonMissingPath('audio_url');
        $id = $created->json('id');
        $this->assertNotSame($data['audio_url'], DB::table('audio_calls')->where('id', $id)->value('audio_url'));
        $this->postJson(self::API, $data, $headers)->assertAccepted()->assertJsonPath('id', $id);
        $this->postJson(self::API, array_replace($data, ['to' => '+989121234568']), $headers)->assertConflict();
        $this->postJson(self::API, $data + ['audio_id' => $id], $headers)->assertUnprocessable();
        $this->getJson(self::API.'/'.$id, ['Authorization' => 'Bearer '.$otherToken])->assertNotFound();
        $this->postJson(self::API, $data + ['caller_id_id' => $line->id], [
            'Authorization' => 'Bearer '.$otherToken, 'Idempotency-Key' => 'other',
        ])->assertUnprocessable();
        $switch = $this->mock(SwitchCallControl::class);
        $switch->shouldNotReceive('originateAudio');
        app(AudioCallWorker::class)->tick($switch, fn () => null);
        $this->getJson(self::API.'/'.$id, $headers)->assertOk()->assertJsonPath('status', 'finished')
            ->assertJsonPath('outcome', 'audio_unavailable');
        $this->assertNull(AudioCall::query()->findOrFail($id)->audio_url);
    }

    private function key(int $tenantId): string
    {
        $token = 'bac_'.bin2hex(random_bytes(32));
        DB::table('audio_call_keys')->insert(['tenant_id' => $tenantId, 'name' => 'Test', 'digest' => hash('sha256', $token)]);

        return $token;
    }
}
