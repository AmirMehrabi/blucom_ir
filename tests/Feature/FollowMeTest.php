<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\CallQueue;
use App\Models\InboundRoute;
use App\Models\IvrMenu;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\BlucomOwner;
use App\Services\CustomerAccountService;
use App\Services\FreeSwitchDialplanService;
use App\Services\IvrMenuService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
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

        $this->actingAs($owner, 'customer')->get($page)->assertOk()->assertSee('مسیر پشتیبان')->assertDontSee('Private endpoint');
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

    public function test_mixed_route_executes_queue_overflow_and_ivr_branches_on_the_original_call(): void
    {
        config(['voip.queues_enabled' => true, 'app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        Storage::fake('ivr');
        Storage::disk('ivr')->put('greeting.wav', 'greeting');
        $tenant = app(BlucomOwner::class)->get();
        [$phone, $agent, $choice, $backup] = SipExtension::factory()->count(4)->for($tenant)->create();
        $menu = IvrMenu::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'After hours', 'version' => 1, 'enabled' => true,
            'published_config' => [
                'greeting' => 'greeting.wav',
                'choices' => [1 => ['label' => 'Duty phone', 'destination' => 'extension:'.$choice->id]],
                'fallback' => 'extension:'.$backup->id,
            ],
        ]);
        $queue = CallQueue::query()->create([
            'tenant_id' => $tenant->id, 'name' => 'Support', 'strategy' => 'ring-all', 'max_wait_seconds' => 30, 'enabled' => true,
        ]);
        $queue->members()->attach($agent);
        $queue->forceFill(['follow_me' => ['enabled' => true, 'targets' => [['destination' => 'ivr:'.$menu->id]]]])->save();
        $phone->forceFill(['follow_me' => ['enabled' => true, 'targets' => [
            ['destination' => 'queue:'.$queue->id],
            ['destination' => 'extension:'.$backup->id, 'seconds' => 22],
        ]]])->save();

        $wire = ['Unique-ID' => '8a4c9ed7-9daf-4e96-83cf-e610a9600001'];
        $dialplan = app(FreeSwitchDialplanService::class);
        $xml = simplexml_load_string($dialplan->build('default', $wire + ['variable_sip_auth_username' => $agent->sipIdentity()]));
        $steps = $xml->xpath('//extension[@name="local_'.$phone->id.'"]//action[@application="execute_extension"]');
        $this->assertCount(2, $steps);
        $queueXml = $this->continueFollowMe((string) $steps[0]['data'], $wire);
        $this->assertStringContainsString($queue->freeSwitchName(), $queueXml);
        $queueExit = $this->followMeStep($queueXml);
        $this->assertStringNotContainsString('execute_extension', $this->continueFollowMe($queueExit, $wire + ['variable_cc_cause' => 'answered']));
        $overflow = $this->continueFollowMe($queueExit, $wire + ['variable_cc_cancel_reason' => 'TIMEOUT']);
        $ivr = $this->continueFollowMe($this->followMeStep($overflow), $wire);
        $this->assertStringContainsString('play_and_get_digits', $ivr);
        $ivrExit = $this->followMeStep($ivr);
        $selected = $this->continueFollowMe($ivrExit, $wire + ['variable_blucom_ivr_digit' => '1']);
        $this->assertStringContainsString('user/'.$choice->sipIdentity().'@', $this->continueFollowMe($this->followMeStep($selected), $wire));
        $this->assertStringNotContainsString('callcenter', $this->continueFollowMe((string) $steps[0]['data'], $wire));
        $this->assertStringNotContainsString('bridge', $this->continueFollowMe((string) $steps[1]['data'], ['Unique-ID' => '8a4c9ed7-9daf-4e96-83cf-e610a9600002']));
        $this->assertStringContainsString('[leg_timeout=22]user/'.$backup->sipIdentity().'@', $this->continueFollowMe((string) $steps[1]['data'], $wire));
    }

    public function test_mixed_destinations_can_be_saved_and_ivr_publish_cannot_close_a_cycle(): void
    {
        config(['voip.queues_enabled' => true]);
        Storage::fake('ivr');
        Storage::disk('ivr')->put('greeting.wav', 'greeting');
        $owner = app(CustomerAccountService::class)->createOwner('Customer', '+989120000001', 'Tenant');
        [$phone, $backup] = SipExtension::factory()->count(2)->create(['tenant_id' => $owner->tenant_id]);
        $queue = CallQueue::query()->create([
            'tenant_id' => $owner->tenant_id, 'name' => 'Support', 'strategy' => 'ring-all', 'max_wait_seconds' => 30, 'enabled' => true,
        ]);
        $queue->members()->attach($backup);
        $config = [
            'greeting' => 'greeting.wav',
            'choices' => [1 => ['label' => 'Duty', 'destination' => 'extension:'.$backup->id]],
            'fallback' => 'extension:'.$backup->id,
        ];
        $menu = IvrMenu::query()->create([
            'tenant_id' => $owner->tenant_id, 'name' => 'Welcome menu', 'version' => 1, 'enabled' => true,
            'published_config' => $config,
            'draft_config' => array_replace($config, ['fallback' => 'extension:'.$phone->id]),
        ]);
        $page = 'https://my.blucom.ir/extensions/'.$phone->id;
        $steps = [
            ['destination' => 'extension:'.$backup->id, 'seconds' => 10],
            ['destination' => 'queue:'.$queue->id],
            ['destination' => 'ivr:'.$menu->id],
        ];
        $this->actingAs($owner, 'customer')->put($page.'/follow-me', ['follow_me_enabled' => 1, 'follow_me_targets' => $steps])->assertRedirect($page.'#follow-me');
        $this->assertSame($steps, $phone->fresh()->follow_me['targets']);
        $this->get($page)->assertOk()->assertSee('افزودن مقصد')->assertSee('نمای مسیر ذخیره‌شده')->assertSee($menu->name);

        $queueSteps = [['destination' => 'ivr:'.$menu->id], ['destination' => 'extension:'.$backup->id, 'seconds' => 15]];
        $queuePage = 'https://my.blucom.ir/teams';
        $this->put($queuePage.'/'.$queue->id.'/follow-me', ['follow_me_enabled' => 1, 'follow_me_targets' => $queueSteps])
            ->assertRedirect($queuePage.'#queue-'.$queue->id);
        $this->assertSame($queueSteps, $queue->fresh()->follow_me['targets']);

        try {
            app(IvrMenuService::class)->publish($menu);
            $this->fail('Publishing would create a cycle.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString($menu->name, $exception->errors()['choices'][0]);
            $this->assertSame($config, $menu->fresh()->published_config);
        }
    }

    private function followMeStep(string $xml): string
    {
        $entry = simplexml_load_string($xml)->xpath('//action[@application="execute_extension"]');
        $this->assertCount(1, $entry);

        return (string) $entry[0]['data'];
    }

    private function continueFollowMe(string $action, array $request): string
    {
        return app(FreeSwitchDialplanService::class)->build('blucom_follow_me',
            $request + ['Hunt-Destination-Number' => explode(' ', $action)[0]]);
    }
}
