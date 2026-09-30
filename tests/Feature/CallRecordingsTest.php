<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\CallRecord;
use App\Models\CallRecording;
use App\Models\InboundRoute;
use App\Models\NumberRecordingSetting;
use App\Models\OutboundRoute;
use App\Models\SipExtension;
use App\Models\SipGateway;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FreeSwitchDialplanService;
use App\Services\RecordingPolicyService;
use App\Services\RecordingProcessor;
use App\Services\RecordingStorageService;
use App\Support\Permissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CallRecordingsTest extends TestCase
{
    use RefreshDatabase;

    private string $spool;

    protected function setUp(): void
    {
        parent::setUp();
        $this->spool = sys_get_temp_dir().'/blucom-recording-tests-'.Str::uuid();
        mkdir($this->spool, 0700);
        config(['voip.recordings.enabled' => true, 'voip.recordings.spool' => $this->spool, 'voip.recordings.min_free_mb' => 0]);
        Storage::fake('recordings');
        Storage::fake('ivr');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->spool);
        parent::tearDown();
    }

    private function user(Tenant $tenant, array $permissions): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'user_type' => UserType::Operator]);
        foreach ($permissions as $permission) {
            $user->permissions()->create(['permission' => $permission]);
        }

        return $user;
    }

    private function policy(SipNumber $number, array $data = []): NumberRecordingSetting
    {
        return NumberRecordingSetting::query()->create($data + ['tenant_id' => $number->tenant_id, 'sip_number_id' => $number->id,
            'directions' => 'both', 'coverage' => 'conversation', 'retention_days' => 30, 'max_minutes' => 1]);
    }

    private function reserve(SipNumber $number): CallRecording
    {
        return app(RecordingPolicyService::class)->reserve($number, 'inbound', ['Caller-Unique-ID' => (string) Str::uuid()]);
    }

    private function completedCall(CallRecording $recording, string $status = 'answered'): CallRecord
    {
        return CallRecord::query()->create(['tenant_id' => $recording->tenant_id, 'sip_number_id' => $recording->sip_number_id,
            'freeswitch_uuid' => $recording->freeswitch_uuid, 'direction' => 'inbound',
            'source_number' => 'CALLER-'.$recording->tenant_id, 'destination_number' => 'DESTINATION',
            'status' => $status, 'started_at' => now()->subMinutes(2), 'ended_at' => now(),
            'duration_seconds' => 120, 'billable_seconds' => 60]);
    }

    private function wav(CallRecording $recording, bool $complete = true): string
    {
        $data = str_repeat("\0", 32000);
        $wav = 'RIFF'.pack('V', 36 + strlen($data)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 2, 8000, 32000, 4, 16).'data'.pack('V', strlen($data)).$data;
        $path = app(RecordingStorageService::class)->spoolPath($recording->id);
        file_put_contents($path, $wav);
        if ($complete) {
            file_put_contents(app(RecordingStorageService::class)->spoolPath($recording->id, 'complete'), 'complete');
        }

        return $path;
    }

    public function test_policies_and_storage_are_tenant_scoped_and_validate_configuration(): void
    {
        $tenant = Tenant::factory()->create();
        $own = SipNumber::factory()->for($tenant)->create();
        $other = SipNumber::factory()->create();
        $user = $this->user($tenant, [Permissions::RECORDINGS_MANAGE]);
        $data = ['directions' => 'both', 'coverage' => 'conversation', 'retention_days' => 7, 'max_minutes' => 30];
        $this->actingAs($user)->get(route('recordings.numbers.edit', $own))->assertOk()->assertSee('ضبط تماس');
        $this->put(route('recordings.numbers.update', $own), $data)->assertRedirect();
        $this->assertDatabaseHas('number_recording_settings', ['sip_number_id' => $own->id, 'tenant_id' => $tenant->id, 'retention_days' => 7]);
        $this->put(route('recordings.numbers.update', $other), $data)->assertNotFound();
        $this->get(route('recordings.numbers.edit', $other))->assertNotFound();
        $this->put(route('recordings.storage', $other->tenant_id), ['quota_mb' => 128, 'overflow' => 'oldest'])->assertNotFound();
        $this->put(route('recordings.numbers.update', $own), ['directions' => 'bad', 'coverage' => 'bad', 'retention_days' => 0, 'max_minutes' => 999])->assertSessionHasErrors();
        $this->put(route('recordings.numbers.update', $own), $data + ['announcement_enabled' => 1])->assertSessionHasErrors('announcement');
        $this->assertDatabaseMissing('number_recording_settings', ['sip_number_id' => $other->id]);
    }

    public function test_recording_access_download_and_delete_permissions_are_independent(): void
    {
        $tenant = Tenant::factory()->create();
        $number = SipNumber::factory()->for($tenant)->create();
        $this->policy($number);
        $recording = $this->reserve($number);
        $this->completedCall($recording);
        $this->wav($recording);
        app(RecordingProcessor::class)->process($recording);
        $viewer = $this->user($tenant, [Permissions::RECORDINGS_VIEW]);
        $this->actingAs($viewer)->get('/recordings')->assertOk()->assertSee('CALLER-'.$tenant->id)->assertSee('data-recording-player', false);
        $this->get(route('recordings.audio', $recording))->assertOk()->assertHeader('Content-Type', 'audio/wav');
        $this->withHeader('Range', 'bytes=0-15')->get(route('recordings.audio', $recording))->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-15/32044');
        $this->get(route('recordings.download', $recording))->assertForbidden();
        $this->delete(route('recordings.destroy', $recording), ['confirm' => 1])->assertForbidden();
        $other = $this->user(Tenant::factory()->create(), [Permissions::RECORDINGS_VIEW, Permissions::RECORDINGS_DOWNLOAD, Permissions::RECORDINGS_DELETE]);
        $this->actingAs($other)->get('/recordings')->assertOk()->assertDontSee('CALLER-'.$tenant->id);
        foreach (['audio', 'download'] as $route) {
            $this->get(route('recordings.'.$route, $recording))->assertNotFound();
        }
        $this->delete(route('recordings.destroy', $recording), ['confirm' => 1])->assertNotFound();
        $viewer->permissions()->create(['permission' => Permissions::RECORDINGS_DOWNLOAD]);
        $this->flushHeaders()->actingAs($viewer)->get(route('recordings.download', $recording))->assertOk()->assertDownload();
    }

    public function test_completed_audio_requires_both_completion_marker_and_completed_cdr(): void
    {
        $number = SipNumber::factory()->create();
        $this->policy($number);
        $recording = $this->reserve($number);
        $path = $this->wav($recording, false);
        $processor = app(RecordingProcessor::class);
        $processor->process($recording);
        $this->assertSame('recording', $recording->fresh()->status);
        $call = $this->completedCall($recording);
        $processor->process($recording);
        $this->assertSame('processing', $recording->fresh()->status);
        $this->assertFileExists($path);
        file_put_contents(app(RecordingStorageService::class)->spoolPath($recording->id, 'complete'), 'complete');
        $processor->process($recording);
        $recording->refresh();
        $this->assertSame('ready', $recording->status);
        $this->assertSame(1, $recording->duration_seconds);
        $this->assertSame(0, $recording->reserved_bytes);
        $this->assertTrue($recording->expires_at->equalTo($call->ended_at->copy()->addDays(30)));
        Storage::disk('recordings')->assertExists($recording->storage_key);
        $processor->process($recording); // Idempotent.
        $this->assertDatabaseCount('call_recordings', 1);
    }

    public function test_recovers_after_atomic_move_before_database_commit(): void
    {
        $number = SipNumber::factory()->create();
        $this->policy($number);
        $recording = $this->reserve($number);
        $this->completedCall($recording);
        $path = $this->wav($recording);
        $key = $number->tenant_id.'/'.$recording->id.'.wav';
        Storage::disk('recordings')->makeDirectory((string) $number->tenant_id);
        rename($path, Storage::disk('recordings')->path($key));
        app(RecordingProcessor::class)->process($recording);
        $this->assertSame('ready', $recording->fresh()->status);
    }

    public function test_reservations_are_idempotent_and_preserve_the_policy_snapshot(): void
    {
        $number = SipNumber::factory()->create();
        $policy = $this->policy($number);
        $recording = $this->reserve($number);
        $policy->update(['directions' => 'off', 'retention_days' => 1]);
        $retry = app(RecordingPolicyService::class)->reserve($number, 'inbound', ['Caller-Unique-ID' => $recording->freeswitch_uuid]);
        $this->assertSame($recording->id, $retry->id);
        $this->assertSame(30, $retry->policy['retention_days']);
        $this->assertNull(app(RecordingPolicyService::class)->reserve($number, 'inbound', ['Caller-Unique-ID' => (string) Str::uuid()]));
        $this->assertNull(app(RecordingPolicyService::class)->reserve($number, 'inbound', ['Caller-Unique-ID' => '../../evil']));
        $other = SipNumber::factory()->create();
        $this->policy($other);
        $this->assertNull(app(RecordingPolicyService::class)->reserve($other, 'inbound', ['Caller-Unique-ID' => $recording->freeswitch_uuid]));
    }

    public function test_quota_includes_concurrent_reservations_and_skips_without_interrupting_calls(): void
    {
        $number = SipNumber::factory()->create();
        $this->policy($number, ['max_minutes' => 60]);
        app(RecordingStorageService::class)->settings($number->tenant_id)->update(['quota_mb' => 128]);
        $first = $this->reserve($number);
        $second = $this->reserve($number);
        $this->assertSame('recording', $first->status);
        $this->assertSame('skipped', $second->status);
        $this->assertSame(0, $second->reserved_bytes);
        $this->assertSame('storage_capacity', $second->failure_reason);
    }

    public function test_oldest_rotation_deletes_ready_audio_and_preserves_live_recordings_and_history(): void
    {
        $number = SipNumber::factory()->create();
        $this->policy($number);
        $old = $this->reserve($number);
        $call = $this->completedCall($old);
        $this->wav($old);
        app(RecordingProcessor::class)->process($old);
        $old->refresh()->update(['bytes' => 133000000]);
        $live = $this->reserve($number);
        app(RecordingStorageService::class)->settings($number->tenant_id)->update(['quota_mb' => 128, 'overflow' => 'oldest']);
        $new = $this->reserve($number);
        $this->assertSame('recording', $new->status);
        $this->assertSame('expired', $old->fresh()->status);
        $this->assertSame('recording', $live->fresh()->status);
        Storage::disk('recordings')->assertMissing($number->tenant_id.'/'.$old->id.'.wav');
        $this->assertDatabaseHas('call_records', ['id' => $call->id]);
    }

    public function test_retention_and_manual_delete_preserve_call_history_and_never_delete_active_files(): void
    {
        $number = SipNumber::factory()->create();
        $this->policy($number);
        $recording = $this->reserve($number);
        $call = $this->completedCall($recording);
        $this->wav($recording);
        app(RecordingProcessor::class)->process($recording);
        $recording->refresh()->update(['expires_at' => now()->subDay()]);
        $active = $this->reserve($number);
        $activePath = $this->wav($active);
        $active->update(['expires_at' => now()->subDay()]);
        $this->artisan('voip:process-recordings')->assertSuccessful();
        $this->assertSame('expired', $recording->fresh()->status);
        $this->assertSame('recording', $active->fresh()->status);
        $this->assertFileExists($activePath);
        $this->assertDatabaseHas('call_records', ['id' => $call->id]);
        $user = $this->user($number->tenant, [Permissions::RECORDINGS_DELETE]);
        $this->actingAs($user)->delete(route('recordings.destroy', $active), ['confirm' => 1])->assertStatus(409);
    }

    public function test_malformed_audio_is_not_published_and_missing_completion_times_out_safely(): void
    {
        $number = SipNumber::factory()->create();
        $this->policy($number);
        $recording = $this->reserve($number);
        $this->completedCall($recording);
        $path = $this->wav($recording);
        file_put_contents($path, '<html>oops</html>');
        app(RecordingProcessor::class)->process($recording);
        $this->assertSame('failed', $recording->fresh()->status);
        $this->assertSame('invalid_audio', $recording->fresh()->failure_reason);
        $other = $this->reserve($number);
        $this->completedCall($other)->update(['ended_at' => now()->subMinutes(20)]);
        $this->wav($other, false);
        app(RecordingProcessor::class)->process($other);
        $this->assertSame('failed', $other->fresh()->status);
        $this->assertSame('completion_missing', $other->fresh()->failure_reason);
    }

    public function test_existing_retention_changes_are_explicit_and_scope_only_the_selected_number(): void
    {
        $number = SipNumber::factory()->create();
        $this->policy($number);
        $recording = $this->reserve($number);
        $this->completedCall($recording);
        $this->wav($recording);
        app(RecordingProcessor::class)->process($recording);
        $user = $this->user($number->tenant, [Permissions::RECORDINGS_MANAGE]);
        $this->actingAs($user)->post(route('recordings.retention', $number), ['retention_days' => 7])->assertSessionHasErrors('confirm');
        $this->assertSame(30, $recording->fresh()->policy['retention_days']);
        $this->post(route('recordings.retention', $number), ['retention_days' => 7, 'confirm' => 1])->assertRedirect();
        $this->assertTrue($recording->fresh()->expires_at->equalTo($recording->fresh()->callRecord->ended_at->copy()->addDays(7)));
    }

    public function test_dialplan_records_only_the_authorized_number_and_keeps_routing_intact(): void
    {
        $tenant = Tenant::factory()->create();
        $gateway = SipGateway::factory()->create(['tenant_id' => null, 'enabled' => true, 'approved_for_outbound' => true, 'verification_status' => 'approved']);
        $number = SipNumber::factory()->for($tenant)->create(['provider_gateway_id' => $gateway->id]);
        $extension = SipExtension::factory()->for($tenant)->create();
        InboundRoute::factory()->for($tenant)->create(['sip_number_id' => $number->id, 'destination_id' => $extension->id]);
        OutboundRoute::factory()->for($tenant)->create(['sip_number_id' => $number->id, 'sip_extension_id' => $extension->id, 'gateway_id' => $gateway->id]);
        $this->policy($number);
        $service = app(FreeSwitchDialplanService::class);
        $request = ['Caller-Unique-ID' => (string) Str::uuid(), 'Caller-Destination-Number' => ltrim($number->normalized_number, '+')];
        $xml = $service->build('public', $request);
        $this->assertStringContainsString('application="record_session"', $xml);
        $this->assertStringContainsString('RECORD_BRIDGE_REQ=true', $xml);
        $this->assertStringContainsString('luarun:blucom_recording_complete.lua', $xml);
        $this->assertStringContainsString('application="transfer"', $xml);
        $this->assertNotFalse(simplexml_load_string($xml));
        $this->assertDatabaseCount('call_recordings', 1);
        $service->build('public', $request);
        $this->assertDatabaseCount('call_recordings', 1);
        $this->assertStringNotContainsString('record_session', $service->build('public', ['Caller-Unique-ID' => (string) Str::uuid(), 'Caller-Destination-Number' => '9999999']));
        $outbound = $service->build('default', ['Caller-Unique-ID' => (string) Str::uuid(), 'variable_sip_auth_username' => $extension->extension, 'Caller-Destination-Number' => '09123456789']);
        $this->assertStringContainsString('application="record_session"', $outbound);
        $this->assertStringContainsString('sofia/gateway/'.$gateway->name, $outbound);
        $this->assertStringContainsString('effective_caller_id_number='.ltrim($number->normalized_number, '+'), $outbound);
        $count = CallRecording::query()->count();
        $this->assertStringNotContainsString('record_session', $service->build('default', [
            'Caller-Unique-ID' => (string) Str::uuid(), 'variable_sip_auth_username' => $extension->extension,
            'Caller-Destination-Number' => $extension->extension,
        ]));
        $this->assertSame($count, CallRecording::query()->count());
        $this->assertStringNotContainsString('record_session', $service->build('default', ['Caller-Unique-ID' => (string) Str::uuid(), 'variable_sip_auth_username' => 'unknown']));
    }

    public function test_disabled_recording_bootstrap_and_cross_tenant_policies_do_not_emit_recording_actions(): void
    {
        $number = SipNumber::factory()->create();
        $this->policy($number, ['tenant_id' => Tenant::factory()->create()->id]);
        $this->assertNull(app(RecordingPolicyService::class)->reserve($number, 'inbound', ['Caller-Unique-ID' => (string) Str::uuid()]));
        config(['voip.recordings.enabled' => false]);
        $number->recordingSetting->update(['tenant_id' => $number->tenant_id]);
        $this->assertNull(app(RecordingPolicyService::class)->reserve($number, 'inbound', ['Caller-Unique-ID' => (string) Str::uuid()]));
    }
}
