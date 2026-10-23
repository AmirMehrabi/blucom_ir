<?php

namespace Tests\Feature;

use App\Models\CallRecord;
use App\Models\Customer;
use App\Models\NumberRecordingSetting;
use App\Models\OutboundCallSession;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Services\CallRecordImporter;
use App\Services\CustomerAccountService;
use App\Services\FreeSwitch\EventSocket;
use App\Services\FreeSwitchDirectoryService;
use App\Services\OutboundCalling\CallJournalService;
use App\Services\OutboundCalling\CallStateService;
use App\Services\OutboundCalling\InitiateTwoPartyCall;
use App\Services\OutboundCalling\OutboundCallWorker;
use App\Services\OutboundCalling\SwitchCallControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class TwoPartyCallsTest extends TestCase
{
    use RefreshDatabase;

    private Customer $owner;

    private OutboundRoute $route;

    private FakeCallControl $control;

    private const URL = 'https://my.blucom.ir/outbound/two-party';

    protected function setUp(): void
    {
        parent::setUp();
        config(['voip.two_party.enabled' => true, 'voip.live.cache_store' => 'array', 'voip.two_party.spool' => storage_path('framework/testing/two-party-spool')]);
        File::ensureDirectoryExists(config('voip.two_party.spool'));
        Cache::put('voip:outbound:healthy_at', now()->timestamp, 20);
        $this->owner = app(CustomerAccountService::class)->createOwner('Owner', '+989120000001', 'Business');
        $extension = SipExtension::factory()->create(['tenant_id' => $this->owner->tenant_id]);
        $gateway = SipGateway::factory()->create(['approved_for_outbound' => true, 'verification_status' => 'approved']);
        $number = SipNumber::factory()->create(['tenant_id' => $this->owner->tenant_id, 'provider_gateway_id' => $gateway->id]);
        $this->route = OutboundRoute::factory()->create(['tenant_id' => $this->owner->tenant_id,
            'sip_extension_id' => $extension->id, 'sip_number_id' => $number->id, 'gateway_id' => $gateway->id]);
        $this->control = new FakeCallControl;
        $this->app->instance(SwitchCallControl::class, $this->control);
    }

    private function input(array $options = []): array
    {
        return $options + ['agent_id' => $this->route->sip_extension_id, 'caller_id' => $this->route->sip_number_id,
            'customer_number' => '09123456789', 'idempotency_key' => (string) Str::uuid()];
    }

    private function newCall(array $options = []): OutboundCallSession
    {
        return app(InitiateTwoPartyCall::class)->handle($this->owner, $this->input($options));
    }

    private function event(OutboundCallSession $call, string $name, bool $agent = true, array $extra = []): void
    {
        app(CallStateService::class)->event($extra + ['Event-Name' => $name, 'Unique-ID' => $agent ? $call->agent_uuid : $call->customer_uuid]);
    }

    private function phase(OutboundCallSession $call, string $phase, array $extra = []): void
    {
        $this->event($call, 'CUSTOM', true, $extra + ['Event-Subclass' => 'blucom::two_party', 'Blucom-Phase' => $phase]);
    }

    public function test_portal_renders_and_submits_without_leaking_switch_configuration(): void
    {
        $this->actingAs($this->owner, 'customer')->get(self::URL)->assertOk()->assertSee('تماس دوطرفه');
        $response = $this->postJson(self::URL, $this->input())->assertAccepted()->assertJsonPath('status', 'created')
            ->assertJsonMissingPath('agent_uuid')->assertJsonMissingPath('configuration');
        $this->getJson($response->json('url'))->assertOk()->assertJsonPath('terminal', false);
    }

    public function test_idempotency_reuses_one_session_and_rejects_different_input(): void
    {
        $input = $this->input();
        $one = app(InitiateTwoPartyCall::class)->handle($this->owner, $input);
        $two = app(InitiateTwoPartyCall::class)->handle($this->owner, $input);
        $this->assertSame($one->id, $two->id);
        $this->assertDatabaseCount('outbound_call_sessions', 1);
        $this->actingAs($this->owner, 'customer')->postJson(self::URL, array_replace($input, ['customer_number' => '09123456788']))->assertConflict();
    }

    public function test_cross_tenant_extension_caller_id_audio_and_session_are_rejected(): void
    {
        $other = app(CustomerAccountService::class)->createOwner('Other', '+989120000002', 'Other');
        $extension = SipExtension::factory()->create(['tenant_id' => $other->tenant_id]);
        $number = SipNumber::factory()->create(['tenant_id' => $other->tenant_id]);
        $this->actingAs($this->owner, 'customer')->postJson(self::URL, $this->input(['agent_id' => $extension->id]))->assertUnprocessable();
        $this->postJson(self::URL, $this->input(['caller_id' => $number->id]))->assertUnprocessable();
        $this->postJson(self::URL, $this->input(['agent_audio_id' => (string) Str::uuid()]))->assertUnprocessable();
        $call = $this->newCall();
        $this->actingAs($other, 'customer')->getJson(self::URL.'/'.$call->id)->assertNotFound();
        $this->postJson(self::URL.'/'.$call->id.'/cancel')->assertNotFound();
    }

    public function test_disabled_route_foreign_gateway_and_unauthorized_destination_fail_closed(): void
    {
        $this->actingAs($this->owner, 'customer')->postJson(self::URL, $this->input(['customer_number' => '+19001234567']))->assertUnprocessable();
        $this->postJson(self::URL, $this->input(['customer_number' => 'sofia/gateway/evil/123']))->assertUnprocessable();
        $this->route->gateway->update(['approved_for_outbound' => false]);
        $this->postJson(self::URL, $this->input())->assertUnprocessable();
    }

    public function test_permission_limits_and_disabled_feature_are_enforced(): void
    {
        $this->newCall();
        $this->newCall();
        $this->actingAs($this->owner, 'customer')->postJson(self::URL, $this->input())->assertTooManyRequests();
        config(['voip.two_party.enabled' => false]);
        $this->postJson(self::URL, $this->input())->assertStatus(503);
        $this->owner->permissions()->where('permission', 'outbound.initiate')->delete();
        $this->postJson(self::URL, $this->input())->assertForbidden();
    }

    public function test_dispatch_is_claimed_before_originate_and_never_retried_after_connection_failure(): void
    {
        $call = $this->newCall();
        $this->control->throwAfterOriginate = true;
        try {
            app(OutboundCallWorker::class)->tick();
        } catch (\RuntimeException) {
        }
        $this->assertSame('sending', $call->fresh()->dispatch_status);
        $this->assertSame(1, $this->control->originates);
        app(OutboundCallWorker::class)->tick();
        $this->assertSame(1, $this->control->originates);
    }

    public function test_changed_authorization_is_checked_again_before_dispatch(): void
    {
        $call = $this->newCall();
        $this->route->update(['enabled' => false]);
        app(OutboundCallWorker::class)->tick();
        $this->assertSame('authorization_failure', $call->fresh()->failure_reason);
        $this->assertSame(0, $this->control->originates);
    }

    public function test_successful_lifecycle_uses_bridge_time_and_creates_one_history_entry(): void
    {
        $call = $this->newCall();
        app(OutboundCallWorker::class)->tick();
        $this->event($call, 'CHANNEL_ANSWER');
        $this->phase($call, 'customer_dialing', ['Blucom-Agent-Ready-At' => now()->timestamp]);
        $this->event($call, 'CHANNEL_PROGRESS', false);
        $this->event($call, 'CHANNEL_ANSWER', false);
        $this->event($call, 'CHANNEL_BRIDGE', true, ['Other-Leg-Unique-ID' => $call->customer_uuid]);
        $this->travel(20)->seconds();
        $this->event($call, 'CHANNEL_HANGUP_COMPLETE', false, ['Hangup-Cause' => 'NORMAL_CLEARING']);
        $call->refresh();
        $this->assertSame('completed', $call->status);
        $this->assertSame(20, $call->conversation_seconds);
        $this->event($call, 'CHANNEL_HANGUP_COMPLETE');
        $this->assertDatabaseCount('call_records', 1);
        $this->assertSame($call->id, CallRecord::first()->outbound_call_session_id);
        app(OutboundCallWorker::class)->tick();
        $this->assertContains($call->agent_uuid, $this->control->killed);
    }

    public function test_busy_unanswered_and_confirmation_failure_do_not_originate_customer(): void
    {
        foreach (['USER_BUSY' => 'agent_busy', 'NO_ANSWER' => 'agent_unanswered'] as $cause => $reason) {
            $call = $this->newCall();
            $this->event($call, 'CHANNEL_HANGUP_COMPLETE', true, ['Hangup-Cause' => $cause]);
            $this->assertSame($reason, $call->fresh()->failure_reason);
            $call->update(['cleanup_completed_at' => now()]);
        }
        $call = $this->newCall(['require_confirmation' => true]);
        $this->phase($call, 'agent_confirmation');
        $this->phase($call, 'failed', ['Blucom-Failure' => 'agent_declined_confirmation']);
        $this->assertSame('agent_declined_confirmation', $call->fresh()->failure_reason);
        $this->assertNull($call->fresh()->customer_dialed_at);
    }

    public function test_announcements_and_confirmation_are_snapshotted_and_states_do_not_regress(): void
    {
        $call = $this->newCall(['require_confirmation' => true]);
        $this->phase($call, 'agent_prompt');
        $this->phase($call, 'agent_confirmation');
        $this->phase($call, 'agent_prompt');
        $this->assertSame('agent_confirmation', $call->fresh()->status);
        $this->phase($call, 'customer_dialing', ['Blucom-Agent-Ready-At' => now()->timestamp]);
        $this->phase($call, 'customer_prompt');
        $this->assertSame('customer_prompt', $call->fresh()->status);
        $this->assertTrue($call->configuration['require_confirmation']);
        $this->assertFalse($call->configuration['recording']);
    }

    public function test_early_media_duplicate_events_and_wrong_bridge_peer_never_establish_conversation(): void
    {
        $call = $this->newCall();
        $this->event($call, 'CHANNEL_PROGRESS_MEDIA', false);
        $this->assertNull($call->fresh()->customer_answered_at);
        $this->event($call, 'CHANNEL_BRIDGE', true, ['Other-Leg-Unique-ID' => (string) Str::uuid()]);
        $this->assertNull($call->fresh()->bridged_at);
        $this->event($call, 'CHANNEL_ANSWER');
        $this->event($call, 'CHANNEL_ANSWER');
        $this->assertSame(1, $call->events()->where('status', 'agent_answered')->count());
    }

    public function test_customer_busy_unreachable_and_unanswered_are_distinct(): void
    {
        foreach (['USER_BUSY' => 'customer_busy', 'NO_ANSWER' => 'customer_unanswered', 'UNALLOCATED_NUMBER' => 'customer_unreachable'] as $cause => $reason) {
            $call = $this->newCall();
            $this->phase($call, 'customer_dialing', ['Blucom-Agent-Ready-At' => now()->timestamp]);
            $this->event($call, 'CHANNEL_HANGUP_COMPLETE', false, ['Hangup-Cause' => $cause]);
            $this->assertSame($reason, $call->fresh()->failure_reason);
            $call->update(['cleanup_completed_at' => now()]);
        }
    }

    public function test_cancel_and_agent_hangup_during_customer_ringing_clean_both_legs(): void
    {
        $call = $this->newCall();
        app(OutboundCallWorker::class)->tick();
        $this->control->live[$call->customer_uuid] = ['blucom_session_id' => $call->id];
        $this->actingAs($this->owner, 'customer')->postJson(self::URL.'/'.$call->id.'/cancel')->assertOk();
        app(OutboundCallWorker::class)->tick();
        $this->assertSame('cancelled', $call->fresh()->status);
        $this->assertContains($call->customer_uuid, $this->control->killed);
    }

    public function test_reconnection_recovers_switch_phase_without_originating_again(): void
    {
        $call = $this->newCall();
        app(OutboundCallWorker::class)->tick();
        $this->control->live[$call->agent_uuid] += ['blucom_agent_ready_at' => now()->timestamp,
            'blucom_phase_agent_answered_at' => now()->timestamp, 'blucom_phase_customer_dialing_at' => now()->timestamp,
            'bridge_uuid' => $call->customer_uuid, 'bridge_epoch' => now()->timestamp];
        app(OutboundCallWorker::class)->tick();
        $this->assertSame('bridged', $call->fresh()->status);
        $this->assertSame(1, $this->control->originates);
    }

    public function test_complete_call_missed_during_outage_is_recovered_from_native_journal(): void
    {
        $call = $this->newCall();
        app(OutboundCallWorker::class)->tick();
        $start = now()->timestamp;
        $this->travel(30)->seconds();
        file_put_contents(app(CallJournalService::class)->path($call), json_encode([
            'session_id' => $call->id, 'agent_uuid' => $call->agent_uuid, 'customer_uuid' => $call->customer_uuid,
            'final' => true, 'agent_answered' => $start, 'customer_dialing' => $start + 1,
            'customer_answered' => $start + 5, 'bridged' => $start + 6, 'ended' => $start + 26,
        ]));
        $this->control->live = [];
        app(OutboundCallWorker::class)->tick();
        $call->refresh();
        $this->assertSame('completed', $call->status);
        $this->assertSame(20, $call->conversation_seconds);
        $this->assertNotNull($call->agent_ready_at);
        $this->assertSame(1, $this->control->originates);
    }

    public function test_delayed_cdr_import_recovers_native_result_before_worker_restart(): void
    {
        $call = $this->newCall();
        $start = now()->timestamp;
        $this->travel(30)->seconds();
        file_put_contents(app(CallJournalService::class)->path($call), json_encode([
            'session_id' => $call->id, 'agent_uuid' => $call->agent_uuid, 'customer_uuid' => $call->customer_uuid,
            'final' => true, 'agent_answered' => $start, 'customer_dialing' => $start + 1,
            'customer_answered' => $start + 5, 'bridged' => $start + 6, 'ended' => $start + 26,
        ]));
        $fields = array_fill(0, 15, '');
        $fields[10] = $call->agent_uuid;
        $fields[6] = now()->utc()->format('Y-m-d H:i:s');
        $this->assertTrue(app(CallRecordImporter::class)->import($fields));
        $this->assertSame('completed', $call->fresh()->status);
        $this->assertSame(20, $call->fresh()->conversation_seconds);
        $this->assertDatabaseCount('call_records', 1);
    }

    public function test_worker_outage_rejects_new_call_instead_of_leaving_it_pending(): void
    {
        Cache::forget('voip:outbound:healthy_at');
        $this->actingAs($this->owner, 'customer')->postJson(self::URL, $this->input())->assertStatus(503);
        $this->assertDatabaseCount('outbound_call_sessions', 0);
    }

    public function test_recording_requires_line_policy_and_reuses_the_existing_reservation(): void
    {
        $this->actingAs($this->owner, 'customer')->postJson(self::URL, $this->input(['recording' => true]))->assertUnprocessable();
        $spool = sys_get_temp_dir().'/blucom-two-party-recording-'.Str::uuid();
        File::ensureDirectoryExists($spool);
        config(['voip.recordings.enabled' => true, 'voip.recordings.spool' => $spool, 'voip.recordings.min_free_mb' => 0]);
        NumberRecordingSetting::query()->create(['tenant_id' => $this->owner->tenant_id,
            'sip_number_id' => $this->route->sip_number_id, 'directions' => 'outbound', 'coverage' => 'conversation',
            'retention_days' => 30, 'max_minutes' => 1]);
        $call = $this->newCall(['recording' => true]);
        $socket = new CapturingCallSocket;
        (new SwitchCallControl($socket))->originate($call);
        $recording = $call->recording()->firstOrFail();
        $this->assertSame($call->agent_uuid, $recording->freeswitch_uuid);
        $this->assertStringContainsString('blucom_recording_path=', $socket->command);
        $this->assertStringContainsString('ignore_early_media=true', $socket->command);
        $this->assertStringNotContainsString('password', $socket->command);
        $this->event($call, 'CHANNEL_HANGUP_COMPLETE', true, ['Hangup-Cause' => 'NO_ANSWER']);
        $this->assertSame('empty', $recording->fresh()->status);
        $this->assertSame(0, $recording->fresh()->reserved_bytes);
        File::deleteDirectory($spool);
    }

    public function test_overlapping_extensions_have_distinct_registration_and_directory_identities(): void
    {
        $one = $this->route->sipExtension;
        $other = app(CustomerAccountService::class)->createOwner('Other', '+989120000002', 'Other');
        SipNumber::factory()->create(['tenant_id' => $other->tenant_id]);
        $two = SipExtension::factory()->create(['tenant_id' => $other->tenant_id, 'extension' => $one->extension]);
        $this->assertNotSame($one->sipIdentity(), $two->sipIdentity());
        $xml = app(FreeSwitchDirectoryService::class)->buildAll($two->sipIdentity());
        $this->assertStringContainsString('id="'.$two->sipIdentity().'"', $xml);
        $this->assertStringNotContainsString('id="'.$one->sipIdentity().'"', $xml);
    }
}

class FakeCallControl extends SwitchCallControl
{
    public int $originates = 0;

    public array $live = [];

    public array $killed = [];

    public bool $throwAfterOriginate = false;

    public function __construct() {}

    public function originate(OutboundCallSession $call): void
    {
        $this->originates++;
        $this->live[$call->agent_uuid] = ['blucom_session_id' => $call->id];
        if ($this->throwAfterOriginate) {
            throw new \RuntimeException('Disconnected');
        }
    }

    public function exists(string $uuid): bool
    {
        return isset($this->live[$uuid]);
    }

    public function snapshot(string $uuid): array
    {
        return $this->live[$uuid];
    }

    public function kill(string $uuid): void
    {
        $this->killed[] = $uuid;
        unset($this->live[$uuid]);
    }
}

class CapturingCallSocket extends EventSocket
{
    public string $command = '';

    public function background(string $command, string $jobUuid): void
    {
        $this->command = $command;
    }
}
