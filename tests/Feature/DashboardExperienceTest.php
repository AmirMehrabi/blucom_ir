<?php

namespace Tests\Feature;

use App\Enums\UserType;
use App\Models\CallRecord;
use App\Models\CallRecording;
use App\Models\InboundRoute;
use App\Models\SipExtension;
use App\Models\SipNumber;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CallSummaryService;
use App\Services\DashboardAttentionService;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class DashboardExperienceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00', 'Asia/Tehran'));
        config(['voip.display_timezone' => 'Asia/Tehran']);
    }

    public function test_period_is_saved_per_account_and_persists_across_visits_with_filters(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $other = $this->operator($tenant);
        $number = SipNumber::factory()->for($tenant)->create();
        $this->actingAs($user)->post('/dashboard/preference', ['period' => 'weekly', 'number' => $number->id])
            ->assertRedirect(route('dashboard', ['number' => $number->id]));
        $this->assertSame('weekly', $user->fresh()->dashboard_period);
        $this->assertSame('daily', $other->fresh()->dashboard_period);
        $this->flushSession();
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk()->assertViewHas('period', 'weekly')->assertSee('۷ روز اخیر');
        $this->post('/dashboard/preference', ['period' => 'monthly'])->assertRedirect(route('dashboard'));
        $this->actingAs($user->fresh())->get('/dashboard')->assertOk()->assertViewHas('period', 'monthly')->assertSee('۳۰ روز اخیر');
        $this->postJson('/dashboard/preference', ['period' => 'invalid'])->assertUnprocessable();
        $this->assertSame('monthly', $user->fresh()->dashboard_period);
        $this->actingAs($other)->get('/dashboard')->assertViewHas('period', 'daily');
    }

    public function test_period_bounds_use_tehran_midnight_and_answer_rate_excludes_outgoing_calls(): void
    {
        $tenant = Tenant::factory()->create();
        $this->createCall($tenant, '2026-09-29 20:29:59'); // Yesterday in Tehran.
        $this->createCall($tenant, '2026-09-29 20:30:00'); // Today in Tehran.
        $this->createCall($tenant, now()->utc()->toDateTimeString(), ['status' => 'missed']);
        $this->createCall($tenant, now()->utc()->toDateTimeString(), ['direction' => 'outbound']);
        $this->createCall($tenant, now()->utc()->addMinute()->toDateTimeString()); // Future data excluded.
        $this->createCall(Tenant::factory()->create(), now()->utc()->toDateTimeString());
        $this->createCall($tenant, '2026-09-23 20:30:00'); // Weekly start.
        $this->createCall($tenant, '2026-08-31 20:30:00'); // Monthly start.
        $this->createCall($tenant, '2026-08-31 20:29:59'); // Outside monthly window.
        $service = app(CallSummaryService::class);
        $daily = $service->summarize($tenant->id);
        $this->assertSame(3, $daily['total']);
        $this->assertSame(2, $daily['incoming']);
        $this->assertSame(50.0, $daily['answerRate']);
        $this->assertSame(1, $daily['outgoing_answered']);
        $this->assertSame(1, $daily['series'][0]['in']);
        $this->assertSame(5, $service->summarize($tenant->id, 'weekly')['total']);
        $this->assertSame(6, $service->summarize($tenant->id, 'monthly')['total']);
        $this->assertSame(30, count($service->summarize($tenant->id, 'monthly')['series']));
        $empty = $service->summarize(Tenant::factory()->create()->id);
        $this->assertNull($empty['answerRate']);
        $this->assertNull($empty['outgoingRate']);
    }

    public function test_comparison_uses_previous_period_up_to_the_same_time_of_day(): void
    {
        $tenant = Tenant::factory()->create();
        $this->createCall($tenant, '2026-09-29 05:30:00'); // Yesterday at 09:00.
        $this->createCall($tenant, '2026-09-29 10:30:00'); // Yesterday at 14:00, excluded from comparison.
        $this->createCall($tenant, '2026-09-30 05:30:00');
        $summary = app(CallSummaryService::class)->summarize($tenant->id);
        $this->assertSame(1, $summary['previous']['incoming']);
        $this->assertSame(1, $summary['incoming']);
    }

    public function test_card_drilldowns_and_history_apply_the_same_number_date_and_tenant_filters(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $number = SipNumber::factory()->for($tenant)->create();
        $otherNumber = SipNumber::factory()->for($tenant)->create();
        $foreignNumber = SipNumber::factory()->create();
        $own = $this->createCall($tenant, '2026-09-30 05:30:00', ['sip_number_id' => $number->id, 'source_number' => 'MATCHED_CALL', 'status' => 'missed']);
        $this->createCall($tenant, '2026-09-30 06:30:00', ['sip_number_id' => $number->id, 'source_number' => 'OTHER_HOUR']);
        $this->createCall($tenant, '2026-09-30 05:30:00', ['sip_number_id' => $otherNumber->id, 'source_number' => 'OTHER_LINE']);
        $foreign = $this->createCall(Tenant::find($foreignNumber->tenant_id), '2026-09-30 05:30:00', ['sip_number_id' => $foreignNumber->id, 'source_number' => 'FOREIGN_CALL']);
        $filters = ['range' => 'custom', 'from' => '2026-09-30', 'to' => '2026-09-30', 'number' => $number->id, 'direction' => 'inbound', 'status' => 'missed'];
        $this->actingAs($user)->get('/dashboard?number='.$number->id)->assertOk()
            ->assertViewHas('callSummary', fn ($s) => $s['incoming'] === 2)
            ->assertSee(route('calls.index', ['number' => (string) $number->id, 'range' => 'custom', 'from' => '2026-09-30', 'to' => '2026-09-30', 'direction' => 'inbound', 'status' => 'missed']))
            ->assertDontSee('FOREIGN_CALL')->assertDontSee('OTHER_LINE');
        $this->get(route('calls.index', $filters))->assertOk()->assertSee('MATCHED_CALL')->assertDontSee('OTHER_HOUR')->assertDontSee('OTHER_LINE');
        $this->get(route('calls.index', array_diff_key($filters, ['status' => 1]) + ['hour' => 9]))->assertOk()->assertSee('MATCHED_CALL')->assertDontSee('OTHER_HOUR');
        $this->get('/dashboard?number='.$foreignNumber->id)->assertNotFound();
        $this->get('/calls?number='.$foreignNumber->id)->assertNotFound();
        $this->post('/dashboard/preference', ['period' => 'weekly', 'number' => $foreignNumber->id])->assertNotFound();
        $this->assertSame('daily', $user->fresh()->dashboard_period);
        $this->get(route('calls.show', $foreign))->assertNotFound();
        $this->get(route('calls.show', $own))->assertOk()->assertSee('MATCHED_CALL');
    }

    public function test_attention_excludes_active_recordings_and_respects_recording_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $user->permissions()->create(['permission' => Permissions::RECORDINGS_VIEW]);
        $active = $this->createCall($tenant, now()->utc()->subMinutes(10)->toDateTimeString(), ['ended_at' => null]);
        $delayed = $this->createCall($tenant, now()->utc()->subMinutes(10)->toDateTimeString(), ['ended_at' => now()->subMinutes(4)]);
        foreach ([[$active, 'recording'], [$delayed, 'processing']] as [$call, $status]) {
            CallRecording::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'call_record_id' => $call->id,
                'freeswitch_uuid' => $call->freeswitch_uuid, 'direction' => 'inbound', 'status' => $status, 'policy' => []]);
        }
        $summary = app(DashboardAttentionService::class)->summarize($user, $tenant->id);
        $this->assertCount(1, $summary['alerts']);
        $this->assertSame('1 ضبط با تأخیر در آماده‌سازی', $summary['alerts'][0]['title']);
        $user->permissions()->where('permission', Permissions::RECORDINGS_VIEW)->delete();
        $this->assertSame([], app(DashboardAttentionService::class)->summarize($user, $tenant->id)['alerts']);
    }

    public function test_dashboard_permissions_and_empty_metrics_do_not_expose_call_or_recording_data(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertSee('هنوز تماس ورودی ثبت نشده')->assertDontSee('0٪');
        $this->createCall($tenant, now()->utc()->toDateTimeString(), ['source_number' => 'PRIVATE_CALL']);
        $user->permissions()->where('permission', Permissions::CALLS_VIEW)->delete();
        $this->get('/dashboard')->assertOk()->assertDontSee('PRIVATE_CALL')->assertDontSee('روند تماس‌ها');
        $user->permissions()->where('permission', Permissions::DASHBOARD_VIEW)->delete();
        $this->post('/dashboard/preference', ['period' => 'weekly'])->assertForbidden();
        $admin = User::factory()->create(['user_type' => UserType::Admin]);
        $this->actingAs($admin)->get('/dashboard')->assertOk()->assertSee('PRIVATE_CALL')->assertSee('نمای کلی پلتفرم');
    }

    public function test_missing_destination_attention_counts_disabled_targets_but_not_valid_routes(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $user->permissions()->create(['permission' => Permissions::LINES_VIEW]);
        foreach ([true, false] as $enabled) {
            $number = SipNumber::factory()->for($tenant)->create(['status' => 'assigned', 'enabled' => true, 'inbound_enabled' => true]);
            $extension = SipExtension::factory()->for($tenant)->create(['enabled' => $enabled]);
            InboundRoute::factory()->for($tenant)->create(['sip_number_id' => $number->id, 'destination_type' => 'extension', 'destination_id' => $extension->id, 'enabled' => true]);
        }
        $summary = app(DashboardAttentionService::class)->summarize($user, $tenant->id);
        $this->assertCount(1, $summary['alerts']);
        $this->assertSame('1 خط بدون مقصد ورودی فعال', $summary['alerts'][0]['title']);
    }

    public function test_recent_call_recording_actions_require_their_separate_permissions(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $call = $this->createCall($tenant, now()->utc()->toDateTimeString());
        $recording = CallRecording::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id,
            'call_record_id' => $call->id, 'freeswitch_uuid' => $call->freeswitch_uuid,
            'direction' => 'inbound', 'status' => 'ready', 'expires_at' => now()->addDay(), 'policy' => []]);
        $play = route('recordings.index', ['play' => $recording->id]);
        $download = route('recordings.download', $recording->id);
        $this->actingAs($user)->get('/dashboard')->assertOk()->assertDontSee($play);
        $user->permissions()->create(['permission' => Permissions::RECORDINGS_VIEW]);
        $this->get('/dashboard')->assertOk()->assertSee($play)->assertDontSee($download);
        $this->get(route('calls.show', $call))->assertOk()->assertSee($play)->assertDontSee($download);
        $user->permissions()->create(['permission' => Permissions::RECORDINGS_DOWNLOAD]);
        $this->get('/dashboard')->assertOk()->assertSee($download);
        $recording->update(['expires_at' => now()->subMinute()]);
        $this->get('/dashboard')->assertOk()->assertDontSee($play)->assertSee('منقضی‌شده');
    }

    private function operator(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        foreach ([Permissions::DASHBOARD_VIEW, Permissions::CALLS_VIEW] as $permission) {
            $user->permissions()->create(['permission' => $permission]);
        }

        return $user;
    }

    private function createCall(Tenant $tenant, string $started, array $extra = []): CallRecord
    {
        return CallRecord::create($extra + ['tenant_id' => $tenant->id, 'freeswitch_uuid' => (string) Str::uuid(),
            'direction' => 'inbound', 'status' => 'answered', 'source_number' => 'CALLER', 'destination_number' => 'LINE',
            'started_at' => CarbonImmutable::parse($started, 'UTC'), 'ended_at' => CarbonImmutable::parse($started, 'UTC')->addMinute(),
            'duration_seconds' => 60, 'billable_seconds' => 60]);
    }
}
