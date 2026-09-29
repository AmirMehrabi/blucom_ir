<?php

namespace Tests\Feature;

use App\Models\InboundRoute;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Services\BlucomOwner;
use App\Services\InboundScheduleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InboundScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_jalali_closure_and_weekly_hours_are_evaluated_in_tehran(): void
    {
        $service = app(InboundScheduleService::class);
        $schedule = $service->fromInput([
            'timezone' => 'Asia/Tehran',
            'weekly' => [3 => [['start' => '۰۹:۰۰', 'end' => '۱۷:۰۰']]],
            'closed_dates' => ['۱۴۰۵/۰۷/۰۷'],
        ]);
        $this->assertSame(['2026-09-29'], $schedule['closed_dates']);
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create();
        $extension = SipExtension::factory()->for($tenant)->create();
        $route = InboundRoute::factory()->create([
            'tenant_id' => $tenant->id, 'sip_number_id' => $number->id,
            'destination_id' => $extension->id, 'schedule' => $schedule,
        ]);
        $this->assertFalse($service->isOpen($route, CarbonImmutable::parse('2026-09-29 10:00', 'Asia/Tehran')));
        $this->assertTrue($service->isOpen($route, CarbonImmutable::parse('2026-10-06 09:00', 'Asia/Tehran')));
        $this->assertFalse($service->isOpen($route, CarbonImmutable::parse('2026-10-06 17:00', 'Asia/Tehran')));
    }

    public function test_invalid_jalali_date_is_rejected(): void
    {
        $this->expectException(ValidationException::class);
        app(InboundScheduleService::class)->fromInput([
            'timezone' => 'Asia/Tehran', 'weekly' => [0 => [['start' => '09:00', 'end' => '17:00']]],
            'closed_dates' => ['۱۴۰۵/۰۷/۳۲'],
        ]);
    }

    public function test_public_xml_uses_closed_action_and_never_falls_through_to_open_extension(): void
    {
        config(['voip.xml_curl.token' => 'test-token', 'voip.gateway_xml_enabled' => true]);
        $tenant = app(BlucomOwner::class)->get();
        $number = SipNumber::factory()->for($tenant)->create();
        $extension = SipExtension::factory()->for($tenant)->create();
        $closedExtension = SipExtension::factory()->for($tenant)->create();
        $route = InboundRoute::factory()->create([
            'tenant_id' => $tenant->id, 'sip_number_id' => $number->id,
            'destination_id' => $extension->id,
            'schedule' => ['timezone' => 'Asia/Tehran', 'weekly' => array_fill(0, 7, []), 'closed_dates' => []],
            'closed_destination_type' => 'extension', 'closed_destination_id' => $closedExtension->id,
        ]);
        $xml = $this->withHeader('X-FS-Token', 'test-token')->post('/internal/freeswitch/xml',
            ['section' => 'dialplan', 'context' => 'public'])->getContent();
        $this->assertStringContainsString('user/'.$closedExtension->extension.'@', $xml);
        $this->assertStringNotContainsString('data="'.$extension->extension.' XML default"', $xml);

        $route->update(['closed_destination_type' => 'disconnect', 'closed_destination_id' => null]);
        $xml = $this->withHeader('X-FS-Token', 'test-token')->post('/internal/freeswitch/xml',
            ['section' => 'dialplan', 'context' => 'public'])->getContent();
        $this->assertStringContainsString('inbound_'.$number->id, $xml);
        $this->assertStringContainsString('application="hangup"', $xml);
        $this->assertStringNotContainsString('user/'.$extension->extension.'@', $xml);

        Storage::fake('ivr');
        $path = 'announcements/'.$tenant->id.'/'.$number->id.'/01HAAAAAAAAAAAAAAAAAAAAAAA.wav';
        Storage::disk('ivr')->put($path, 'RIFF');
        $route->update(['closed_destination_type' => 'announcement', 'closed_announcement_path' => $path]);
        $xml = $this->withHeader('X-FS-Token', 'test-token')->post('/internal/freeswitch/xml',
            ['section' => 'dialplan', 'context' => 'public'])->getContent();
        $this->assertStringContainsString('application="playback"', $xml);
        $this->assertStringContainsString('application="hangup"', $xml);
    }
}
