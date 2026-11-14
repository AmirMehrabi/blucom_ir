<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BlucomOwner;
use App\Services\CustomerAccountService;
use App\Services\FreeSwitchDialplanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowMeTest extends TestCase
{
    use RefreshDatabase;

    public function test_extension_form_persists_order_and_rejects_unauthorized_targets(): void
    {
        $owner = app(CustomerAccountService::class)->createOwner('Customer', '+989120000001', 'Tenant');
        $phone = SipExtension::factory()->create(['tenant_id' => $owner->tenant_id]);
        $backups = SipExtension::factory()->count(2)->create(['tenant_id' => $owner->tenant_id]);
        $outsider = SipExtension::factory()->for(Tenant::factory())->create(['display_name' => 'Private endpoint']);
        $page = 'https://my.blucom.ir/extensions/'.$phone->id;
        $url = $page.'/follow-me';

        $this->actingAs($owner, 'customer')->get($page)->assertOk()->assertSee('دنبال‌کننده تماس')->assertDontSee('Private endpoint');
        $draft = ['follow_me_form' => 'sip_extensions:'.$phone->id, 'follow_me_enabled' => 1, 'follow_me_targets' => [
            ['id' => $backups[0]->id, 'seconds' => 8],
            ['id' => $backups[0]->id, 'seconds' => 12],
        ]];
        $this->from($page)->put($url, $draft)->assertSessionHas('follow_me_errors', fn ($errors) => isset($errors['follow_me_targets.1.id']));
        $this->get($page)->assertOk()->assertSee('هر داخلی را فقط یک بار انتخاب کنید.')->assertSee('value="12"', false);

        foreach ([$phone, $outsider] as $invalid) {
            $this->from($page)->put($url, ['follow_me_enabled' => 1, 'follow_me_targets' => [['id' => $invalid->id, 'seconds' => 10]]])
                ->assertSessionHas('follow_me_errors', fn ($errors) => isset($errors['follow_me_targets.0.id']));
        }
        $this->put('https://my.blucom.ir/extensions/'.$outsider->id.'/follow-me', ['follow_me_enabled' => 0])->assertNotFound();

        $targets = [['id' => $backups[1]->id, 'seconds' => 10], ['id' => $backups[0]->id, 'seconds' => 20]];
        $this->from($page)->put($url, ['follow_me_enabled' => 1, 'follow_me_targets' => $targets])
            ->assertRedirect($page.'#follow-me');
        $this->assertSame(['enabled' => true, 'targets' => $targets], $phone->fresh()->follow_me);
        $this->get($page)->assertOk()->assertSee('مسیر پشتیبان ذخیره شد.');
        $this->put($url, ['follow_me_enabled' => 0, 'follow_me_targets' => $targets])->assertRedirect();
        $this->assertSame(['enabled' => false, 'targets' => $targets], $phone->fresh()->follow_me);
    }

    public function test_extension_dialplan_uses_order_validates_live_targets_and_does_not_recurse(): void
    {
        $tenant = app(BlucomOwner::class)->get();
        [$phone, $backup, $paused, $last] = SipExtension::factory()->count(4)->for($tenant)->create();
        $outsider = SipExtension::factory()->for(Tenant::factory())->create();
        $paused->forceFill(['paused_at' => now()])->save();
        $phone->forceFill(['follow_me' => ['enabled' => true, 'targets' => [
            ['id' => $backup->id, 'seconds' => 7],
            ['id' => $paused->id, 'seconds' => 8],
            ['id' => $last->id, 'seconds' => 9],
        ]]])->save();
        $backup->forceFill(['follow_me' => ['enabled' => true, 'targets' => [['id' => $outsider->id, 'seconds' => 10]]]])->save();
        $number = SipNumber::factory()->for($tenant)->create();
        InboundRoute::query()->create([
            'tenant_id' => $tenant->id, 'sip_number_id' => $number->id, 'enabled' => true,
            'destination_type' => 'extension', 'destination_id' => $phone->id,
        ]);

        $dialplan = app(FreeSwitchDialplanService::class);
        $public = simplexml_load_string($dialplan->build('public'));
        $default = simplexml_load_string($dialplan->build('default', ['variable_sip_auth_username' => $last->sipIdentity()]));
        foreach ([$public->xpath('//extension[@name="inbound_'.$number->id.'"]'), $default->xpath('//extension[@name="local_'.$phone->id.'"]')] as $entry) {
            $bridges = $entry[0]->xpath('.//action[@application="bridge"]');
            $this->assertCount(3, $bridges);
            foreach ([[$phone, 15], [$backup, 7], [$last, 9]] as $position => [$target, $seconds]) {
                $this->assertStringContainsString('[leg_timeout='.$seconds.']user/'.$target->sipIdentity().'@', (string) $bridges[$position]['data']);
            }
        }
        $phone->forceFill(['paused_at' => now(), 'follow_me' => ['enabled' => true, 'targets' => [
            ['id' => $outsider->id, 'seconds' => 7],
            ['id' => $backup->id, 'seconds' => 8],
        ]]])->save();
        $xml = $dialplan->build('public');
        $this->assertStringNotContainsString('user/'.$phone->sipIdentity().'@', $xml);
        $this->assertStringNotContainsString('user/'.$outsider->sipIdentity().'@', $xml);
        $this->assertStringContainsString('user/'.$backup->sipIdentity().'@', $xml);
    }

    public function test_queue_form_and_xml_only_run_overflow_on_queue_timeouts(): void
    {
        config(['voip.queues_enabled' => true]);
        $tenant = app(BlucomOwner::class)->get();
        [$member, $backup] = SipExtension::factory()->count(2)->for($tenant)->create();
        $queue = CallQueue::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Support', 'strategy' => 'ring-all',
            'max_wait_seconds' => 30, 'fallback_extension_id' => $member->id, 'enabled' => true,
        ]);
        $queue->members()->attach($member);
        $number = SipNumber::factory()->for($tenant)->create();
        InboundRoute::query()->create([
            'tenant_id' => $tenant->id, 'sip_number_id' => $number->id, 'enabled' => true,
            'destination_type' => 'queue', 'destination_id' => $queue->id,
        ]);

        $this->actingAs(User::factory()->create(['user_type' => UserType::Admin]))
            ->put('/teams/'.$queue->id.'/follow-me', ['follow_me_enabled' => 1, 'follow_me_targets' => [['id' => $backup->id, 'seconds' => 12]]])
            ->assertRedirect(route('teams.index').'#queue-'.$queue->id);
        $this->get('/teams')->assertOk()->assertSee('مسیر پشتیبان فعال')->assertSee('مسیر پشتیبان ذخیره شد.');
        $dialplan = app(FreeSwitchDialplanService::class);
        $public = $dialplan->build('public');
        $this->assertStringContainsString('application="callcenter"', $public);
        $this->assertStringContainsString('blucom-queue-audio XML blucom_queue_audio', $public);
        $this->assertStringNotContainsString('application="bridge"', $public);

        $request = ['variable_blucom_sip_number_id' => $number->id, 'variable_blucom_queue_id' => $queue->id];
        foreach (['TIMEOUT', 'NO_AGENT_TIMEOUT', 'BREAK_OUT', 'NONE'] as $reason) {
            $xml = $dialplan->build('blucom_queue_audio', $request + ['variable_cc_cancel_reason' => $reason]);
            $this->assertSame(in_array($reason, ['TIMEOUT', 'NO_AGENT_TIMEOUT'], true), str_contains($xml, '[leg_timeout=12]user/'.$backup->sipIdentity().'@'));
            $this->assertStringNotContainsString('user/'.$member->sipIdentity().'@', $xml);
        }
    }
}
