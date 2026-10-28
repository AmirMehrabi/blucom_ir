<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\SipGateway;
use App\Models\User;
use App\Services\FreeSwitch\GatewayLiveStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GatewayLiveStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_parser_keeps_only_safe_status_fields_and_handles_non_registering_gateways(): void
    {
        $service = new GatewayLiveStatus;
        $rows = $service->parse('<gateways><gateway><name>carrier</name><profile>external</profile><state>REGED</state><status>UP</status><pingfreq>30</pingfreq><password>secret</password><username>private</username></gateway><gateway><name>ip</name><profile>external</profile><state>NOREG</state><status>UP</status><pingfreq>0</pingfreq></gateway></gateways>');
        $this->assertSame(['state' => 'REGED', 'status' => 'UP', 'ping_enabled' => true], $rows['external']['carrier']);
        $this->assertFalse($rows['external']['ip']['ping_enabled']);
        $this->assertStringNotContainsString('secret', json_encode($rows));
        $this->assertSame([], $service->parse('<gateways/>'));
    }

    public function test_malformed_and_entity_documents_are_rejected(): void
    {
        foreach (['-ERR unavailable', '<gateways>', '<!DOCTYPE gateways [<!ENTITY x SYSTEM "file:///etc/passwd">]><gateways/>', '<profile/>'] as $xml) {
            try {
                (new GatewayLiveStatus)->parse($xml);
                $this->fail('Invalid snapshot accepted');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Invalid gateway snapshot.', $exception->getMessage());
            }
        }
    }

    public function test_page_uses_fresh_profile_matched_status_and_rejects_stale_or_disconnected_data(): void
    {
        config(['voip.live.enabled' => true, 'voip.live.cache_store' => 'array']);
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        SipGateway::factory()->create(['name' => 'carrier', 'profile' => 'external']);
        $snapshot = ['connected' => true, 'updated_at' => time(), 'gateways' => ['external' => ['carrier' => ['state' => 'REGED', 'status' => 'UP', 'ping_enabled' => true]]]];
        Cache::store('array')->put('voip:live:gateways', $snapshot);
        $this->actingAs($admin)->get(route('sip-gateways.index'))->assertOk()->assertSee('REGED')->assertSee('دسترس‌پذیر')->assertDontSee('این وضعیت، سلامت اتصال زنده نیست.');
        foreach ([['updated_at' => time() - 21], ['connected' => false]] as $override) {
            Cache::store('array')->put('voip:live:gateways', array_replace($snapshot, $override));
            $this->get(route('sip-gateways.index'))->assertOk()->assertSee('وضعیت زنده نامشخص')->assertDontSee('REGED');
        }
        $snapshot['gateways'] = ['internal' => $snapshot['gateways']['external']];
        Cache::store('array')->put('voip:live:gateways', $snapshot);
        $this->get(route('sip-gateways.index'))->assertSee('در FreeSWITCH بارگذاری نشده')->assertDontSee('REGED');
        config(['voip.live.enabled' => false]);
        $this->get(route('sip-gateways.index'))->assertSee('وضعیت زنده نامشخص');
    }

    public function test_customers_cannot_view_gateway_monitoring(): void
    {
        $this->actingAs(User::factory()->create())->get(route('sip-gateways.index'))->assertForbidden();
    }
}
