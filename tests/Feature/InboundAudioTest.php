<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\TenantAudioAsset;
use App\Models\User;
use App\Services\BlucomOwner;
use App\Services\FreeSwitchDialplanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

class InboundAudioTest extends TestCase
{
    use RefreshDatabase;

    public function test_announcement_upload_can_be_previewed_by_its_tenant(): void
    {
        if (! (new ExecutableFinder)->find('ffmpeg')) {
            $this->markTestSkipped('FFmpeg is required to normalize an audio upload.');
        }
        Storage::fake('ivr');
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $samples = str_repeat("\0", 16000);
        $wave = 'RIFF'.pack('V', 36 + strlen($samples)).'WAVEfmt '
            .pack('VvvVVvv', 16, 1, 1, 8000, 16000, 2, 16).'data'.pack('V', strlen($samples)).$samples;

        $this->actingAs($admin)->post(route('inbound-audio.store'), [
            'name' => 'Pilot announcement',
            'audio' => UploadedFile::fake()->createWithContent('pilot.wav', $wave),
        ])->assertSessionHasNoErrors();
        $asset = TenantAudioAsset::query()->sole();
        $this->get(route('inbound-audio.index'))->assertOk()->assertSee('Pilot announcement');
        $this->get(route('inbound-audio.audio', $asset->id))->assertOk()->assertHeader('Content-Type', 'audio/wav');

        $asset->update(['tenant_id' => Tenant::factory()->create()->id]);
        $this->get(route('inbound-audio.audio', $asset->id))->assertNotFound();
    }

    public function test_direct_announcement_only_plays_a_file_owned_by_the_line_tenant(): void
    {
        Storage::fake('ivr');
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create();
        $id = (string) Str::uuid();
        $path = 'inbound/'.$tenant->id.'/'.$id.'.wav';
        Storage::disk('ivr')->put($path, 'wave');
        $asset = TenantAudioAsset::query()->create([
            'id' => $id, 'tenant_id' => $tenant->id, 'storage_key' => $path,
            'duration_seconds' => 1, 'name' => 'Hours',
        ]);
        InboundRoute::factory()->for($tenant)->for($number)->create([
            'destination_type' => 'audio', 'destination_id' => null,
            'audio_destination' => 'announcement:'.$asset->id,
        ]);

        $dialplan = app(FreeSwitchDialplanService::class);
        $xml = $dialplan->build('public');
        $this->assertStringContainsString('application="playback" data="'.Storage::disk('ivr')->path($path).'"', $xml);
        $this->assertStringContainsString('application="hangup" data="NORMAL_CLEARING"', $xml);
        $this->assertStringNotContainsString('application="bridge"', $xml);

        $asset->update(['tenant_id' => Tenant::factory()->create()->id]);
        $this->assertStringNotContainsString('application="playback"', $dialplan->build('public'));
    }

    public function test_ivr_key_only_accepts_owned_mailboxes_and_uses_published_action(): void
    {
        Storage::fake('ivr');
        config(['voip.voicemail_enabled' => true]);
        $tenant = app(BlucomOwner::class)->get();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $number = SipNumber::factory()->for($tenant)->create();
        $mailbox = SipExtension::factory()->for($tenant)->create();
        $foreign = SipExtension::factory()->create();
        $menu = IvrMenu::query()->create(['tenant_id' => $tenant->id, 'name' => 'Menu', 'enabled' => true]);
        $path = $tenant->id.'/'.$menu->id.'/01KTEST.wav';
        Storage::disk('ivr')->put($path, 'wave');
        $menu->update(['draft_config' => ['greeting' => $path]]);
        $data = ['name' => 'Menu', 'intent' => 'publish', 'fallback' => 'extension:'.$mailbox->id];

        $this->actingAs($admin)->put(route('ivr-menus.update', $menu), $data + [
            'choices' => [2 => ['label' => 'Message', 'destination' => 'voicemail:'.$foreign->id]],
        ])->assertSessionHasErrors('choices');
        $this->put(route('ivr-menus.update', $menu), $data + [
            'choices' => [2 => ['label' => 'Message', 'destination' => 'voicemail:'.$mailbox->id]],
        ])->assertSessionHasNoErrors();
        $this->assertNotNull($mailbox->fresh()->voicemail_secret_encrypted);

        InboundRoute::factory()->for($tenant)->for($number)->create(['destination_type' => 'ivr', 'destination_id' => $menu->id]);
        $request = ['variable_blucom_ivr_menu_id' => $menu->id, 'variable_blucom_ivr_number_id' => $number->id,
            'variable_blucom_ivr_version' => $menu->fresh()->version, 'variable_blucom_ivr_digit' => '2'];
        $dialplan = app(FreeSwitchDialplanService::class);
        $this->assertStringContainsString('application="voicemail" data="default vm-'.$tenant->id.'.blucom.internal '.$mailbox->sipIdentity().'"',
            $dialplan->build('blucom_ivr', $request));
        $mailbox->update(['enabled' => false]);
        $this->assertStringNotContainsString('application="voicemail"', $dialplan->build('blucom_ivr', $request));
    }

    public function test_queue_audio_only_runs_after_an_eligible_timeout(): void
    {
        config(['voip.voicemail_enabled' => true, 'voip.queues_enabled' => true]);
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create();
        $mailbox = SipExtension::factory()->for($tenant)->create(['voicemail_secret_encrypted' => 'test-pin']);
        $queue = CallQueue::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Service', 'strategy' => 'ring-all',
            'enabled' => true, 'fallback_audio_destination' => 'voicemail:'.$mailbox->id,
        ]);
        $queue->members()->attach($mailbox);
        InboundRoute::factory()->for($tenant)->for($number)->create(['destination_type' => 'queue', 'destination_id' => $queue->id]);

        $dialplan = app(FreeSwitchDialplanService::class);
        $this->assertStringContainsString('blucom-queue-audio XML blucom_queue_audio', $dialplan->build('public'));
        $request = ['variable_blucom_sip_number_id' => $number->id, 'variable_blucom_queue_id' => $queue->id];
        foreach (['TIMEOUT', 'NO_AGENT_TIMEOUT'] as $reason) {
            $this->assertStringContainsString('application="voicemail"', $dialplan->build('blucom_queue_audio',
                $request + ['variable_cc_cancel_reason' => $reason]));
        }
        foreach (['NONE', 'EXIT_WITH_KEY', 'BREAK_OUT'] as $reason) {
            $this->assertStringNotContainsString('application="voicemail"', $dialplan->build('blucom_queue_audio',
                $request + ['variable_cc_cancel_reason' => $reason]));
        }
    }

    public function test_destination_settings_validate_tenant_assets_mailboxes_and_the_key(): void
    {
        Storage::fake('ivr');
        config(['voip.voicemail_enabled' => true, 'voip.queues_enabled' => true]);
        $tenant = app(BlucomOwner::class)->get();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $extension = SipExtension::factory()->for($tenant)->create();
        $foreign = SipExtension::factory()->create();
        $asset = $this->asset($tenant);
        $otherAsset = $this->asset($foreign->tenant);
        $queue = CallQueue::query()->create(['tenant_id' => $tenant->id, 'name' => 'Service', 'strategy' => 'ring-all', 'enabled' => true]);

        $extensionUrl = route('inbound-audio.destinations.update', ['extension', $extension->id]);
        $queueUrl = route('inbound-audio.destinations.update', ['queue', $queue->id]);
        $message = ['mode' => 'message', 'asset' => $asset->id, 'digit' => '0'];
        $this->actingAs($admin)->put(route('inbound-audio.destinations.update', ['extension', $foreign->id]), ['mode' => 'normal'])
            ->assertNotFound();
        $this->put($queueUrl, $message + ['mailbox' => $foreign->id])->assertSessionHasErrors('mailbox', null, 'announcement-queue-'.$queue->id);
        $this->put($queueUrl, ['asset' => $otherAsset->id] + $message + ['mailbox' => $extension->id])
            ->assertSessionHasErrors('asset', null, 'announcement-queue-'.$queue->id);
        $this->put($extensionUrl, ['digit' => '#'] + $message)->assertSessionHasErrors('digit', null, 'announcement-extension-'.$extension->id);
        $this->assertNull($extension->fresh()->voicemail_secret_encrypted);

        $this->put($queueUrl, $message + ['mailbox' => $extension->id])->assertSessionHasNoErrors();
        $this->assertSame($message + ['mailbox' => $extension->id], $queue->fresh()->inbound_announcement);
        $this->assertNotNull($extension->fresh()->voicemail_secret_encrypted);
        $this->put($extensionUrl, $message + ['mailbox' => $foreign->id])->assertSessionHasNoErrors();
        $this->assertSame($extension->id, $extension->fresh()->inbound_announcement['mailbox']);
        $this->get(route('teams.index'))->assertOk()->assertSee('announcement-queue-'.$queue->id);
        $this->put($extensionUrl, ['mode' => 'normal'])->assertSessionHasNoErrors();
        $this->assertNull($extension->fresh()->inbound_announcement);
    }

    public function test_destination_announcements_are_terminal_and_ivr_uses_the_same_behavior(): void
    {
        Storage::fake('ivr');
        config(['voip.voicemail_enabled' => true, 'voip.queues_enabled' => true]);
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create();
        $asset = $this->asset($tenant);
        $extension = SipExtension::factory()->for($tenant)->create(['voicemail_secret_encrypted' => 'test-pin']);
        $extension->forceFill(['inbound_announcement' => ['mode' => 'playback', 'asset' => $asset->id], 'paused_at' => now()])->save();
        $queue = CallQueue::query()->create(['tenant_id' => $tenant->id, 'name' => 'Service', 'strategy' => 'ring-all', 'enabled' => true]);
        $queue->members()->attach($extension);
        $queue->forceFill(['inbound_announcement' => [
            'mode' => 'message', 'asset' => $asset->id, 'digit' => '0', 'mailbox' => $extension->id,
        ]])->save();
        $route = InboundRoute::factory()->for($tenant)->for($number)->create(['destination_id' => $extension->id]);
        $dialplan = app(FreeSwitchDialplanService::class);

        $direct = $dialplan->build('public');
        $this->assertStringContainsString('application="playback"', $direct);
        $this->assertStringNotContainsString('application="bridge"', $direct);
        $this->assertStringNotContainsString('blucom_announcement_message.lua', $direct);

        $route->update(['destination_type' => 'queue', 'destination_id' => $queue->id]);
        $queueDialplan = $dialplan->build('public');
        $this->assertStringContainsString('blucom_announcement_message.lua 0 vm-'.$tenant->id.'.blucom.internal '.$extension->sipIdentity(), $queueDialplan);
        $this->assertStringNotContainsString('application="callcenter"', $queueDialplan);
        $this->assertLessThan(strpos($queueDialplan, 'application="flush_dtmf"'), strpos($queueDialplan, 'application="playback"'));

        $menu = IvrMenu::query()->create(['tenant_id' => $tenant->id, 'name' => 'Menu', 'enabled' => true]);
        $menu->update(['version' => 1, 'published_config' => [
            'greeting' => $asset->storage_key, 'choices' => [2 => ['destination' => 'extension:'.$extension->id]],
        ]]);
        $route->update(['destination_type' => 'ivr', 'destination_id' => $menu->id]);
        $request = ['variable_blucom_ivr_menu_id' => $menu->id, 'variable_blucom_ivr_number_id' => $number->id,
            'variable_blucom_ivr_version' => $menu->version, 'variable_blucom_ivr_digit' => '2'];
        $this->assertStringContainsString('application="playback"', $dialplan->build('blucom_ivr', $request));

        $route->update(['destination_type' => 'queue', 'destination_id' => $queue->id]);
        config(['voip.voicemail_enabled' => false]);
        $fallback = $dialplan->build('public');
        $this->assertStringContainsString('application="playback"', $fallback);
        $this->assertStringNotContainsString('blucom_announcement_message.lua', $fallback);

        Storage::disk('ivr')->delete($asset->storage_key);
        $missing = $dialplan->build('public');
        $this->assertStringContainsString('NORMAL_TEMPORARY_FAILURE', $missing);
        $this->assertStringNotContainsString('application="callcenter"', $missing);
    }

    public function test_automatic_announcement_saves_without_a_digit_and_uses_an_owned_mailbox(): void
    {
        Storage::fake('ivr');
        config(['voip.voicemail_enabled' => true, 'voip.queues_enabled' => true]);
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create();
        $asset = $this->asset($tenant);
        $extension = SipExtension::factory()->for($tenant)->create();
        $foreign = SipExtension::factory()->for(Tenant::factory()->create())->create();
        $queue = CallQueue::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Service', 'strategy' => 'ring-all', 'enabled' => true,
        ]);
        $queue->members()->attach($extension);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $data = ['mode' => 'automatic_message', 'asset' => $asset->id];
        $queueSettings = route('inbound-audio.destinations.update', ['queue', $queue->id]);

        $this->actingAs($admin)->put($queueSettings, $data + ['mailbox' => $foreign->id])
            ->assertSessionHasErrorsIn('announcement-queue-'.$queue->id, 'mailbox');
        $this->assertNull($queue->fresh()->inbound_announcement);
        $this->put($queueSettings, $data + ['mailbox' => $extension->id])->assertSessionHasNoErrors();
        $this->assertEquals($data + ['mailbox' => $extension->id], $queue->fresh()->inbound_announcement);
        $this->put(route('inbound-audio.destinations.update', ['extension', $extension->id]), $data)
            ->assertSessionHasNoErrors();
        $this->assertEquals($data + ['mailbox' => $extension->id], $extension->fresh()->inbound_announcement);

        $route = InboundRoute::factory()->for($tenant)->for($number)->create([
            'destination_type' => 'queue', 'destination_id' => $queue->id,
        ]);
        $dialplan = app(FreeSwitchDialplanService::class);
        $handler = 'blucom_announcement_message.lua automatic vm-'.$tenant->id.'.blucom.internal '.$extension->sipIdentity();
        $queueXml = $dialplan->build('public');
        $this->assertStringContainsString($handler, $queueXml);
        $this->assertLessThan(strpos($queueXml, $handler), strpos($queueXml, 'application="playback"'));

        $route->update(['destination_type' => 'extension', 'destination_id' => $extension->id]);
        $this->assertStringContainsString($handler, $dialplan->build('public'));
    }

    public function test_queue_timeout_can_reach_an_extension_announcement(): void
    {
        Storage::fake('ivr');
        config(['voip.queues_enabled' => true]);
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create();
        $asset = $this->asset($tenant);
        $extension = SipExtension::factory()->for($tenant)->create();
        $extension->forceFill(['inbound_announcement' => ['mode' => 'playback', 'asset' => $asset->id]])->save();
        $queue = CallQueue::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Service', 'strategy' => 'ring-all',
            'enabled' => true, 'fallback_extension_id' => $extension->id,
        ]);
        $queue->members()->attach($extension);
        InboundRoute::factory()->for($tenant)->for($number)->create(['destination_type' => 'queue', 'destination_id' => $queue->id]);

        $dialplan = app(FreeSwitchDialplanService::class);
        $this->assertStringContainsString('application="callcenter"', $dialplan->build('public'));
        $this->assertStringContainsString('blucom-queue-audio XML blucom_queue_audio', $dialplan->build('public'));
        $request = ['variable_blucom_sip_number_id' => $number->id, 'variable_blucom_queue_id' => $queue->id];
        $this->assertStringContainsString('application="playback"', $dialplan->build('blucom_queue_audio',
            $request + ['variable_cc_cancel_reason' => 'TIMEOUT']));
        $this->assertStringNotContainsString('application="playback"', $dialplan->build('blucom_queue_audio',
            $request + ['variable_cc_cancel_reason' => 'BREAK_OUT']));
    }

    private function asset(Tenant $tenant): TenantAudioAsset
    {
        $id = (string) Str::uuid();
        $path = 'inbound/'.$tenant->id.'/'.$id.'.wav';
        Storage::disk('ivr')->put($path, 'wave');

        return TenantAudioAsset::query()->create([
            'id' => $id, 'tenant_id' => $tenant->id, 'storage_key' => $path,
            'duration_seconds' => 1, 'name' => 'Hours',
        ]);
    }
}
