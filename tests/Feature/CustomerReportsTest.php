<?php

namespace Tests\Feature;

use App\Models\CallRecord;
use App\Models\SipExtension;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerReportsTest extends TestCase
{
    use RefreshDatabase;

    private function operator(Tenant $tenant): User
    {
        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->permissions()->create(['permission' => Permissions::CALLS_VIEW]);

        return $user;
    }

    private function recordCall(Tenant $tenant, array $attributes = []): CallRecord
    {
        return CallRecord::query()->create(array_merge([
            'tenant_id' => $tenant->id, 'freeswitch_uuid' => (string) Str::uuid(),
            'direction' => 'inbound', 'status' => 'answered', 'source_number' => '+989121234567',
            'destination_number' => '+982112345678', 'started_at' => now()->subMinute(),
            'duration_seconds' => 30, 'billable_seconds' => 20,
        ], $attributes));
    }

    public function test_overview_counts_match_drill_down_and_hide_foreign_calls(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $this->recordCall($tenant);
        $this->recordCall($tenant, ['status' => 'missed']);
        $this->recordCall(Tenant::factory()->create(), ['source_number' => 'PRIVATE_OTHER_CALLER']);
        $response = $this->actingAs($user)->get('/reports?range=30d');
        $response->assertOk()->assertViewHas('stats', fn ($stats) => (int) $stats->total === 2 && (int) $stats->missed === 1)
            ->assertDontSee('PRIVATE_OTHER_CALLER');
        $this->get('/calls?range=30d&status=missed')->assertOk()->assertViewHas('calls', fn ($calls) => $calls->total() === 1);
    }

    public function test_phone_search_matches_both_sides_and_preserves_tenant_scope(): void
    {
        $tenant = Tenant::factory()->create();
        $this->recordCall($tenant, ['source_number' => '00989121234567']);
        $this->recordCall($tenant, ['source_number' => '100', 'destination_number' => '989121234567']);
        $this->recordCall($tenant, ['source_number' => '+989351111111']);
        $this->recordCall(Tenant::factory()->create());
        $this->actingAs($this->operator($tenant))->get('/calls?range=all&phone=%2B989121234567')
            ->assertOk()->assertViewHas('calls', fn ($calls) => $calls->total() === 2);
    }

    public function test_extension_filter_and_options_are_tenant_scoped(): void
    {
        $tenant = Tenant::factory()->create();
        $extension = SipExtension::factory()->for($tenant)->create();
        $foreign = SipExtension::factory()->create();
        $this->recordCall($tenant, ['sip_extension_id' => $extension->id]);
        $this->recordCall($tenant);
        $this->actingAs($this->operator($tenant))->get('/reports?range=all&extension='.$extension->id)
            ->assertOk()->assertViewHas('stats', fn ($stats) => (int) $stats->total === 1)
            ->assertViewHas('extensions', fn ($options) => ! $options->contains('id', $foreign->id));
        $this->get('/reports?extension='.$foreign->id)->assertNotFound();
        $this->get('/calls?extension='.$foreign->id)->assertNotFound();
    }

    public function test_local_day_boundaries_are_shared_and_end_is_exclusive(): void
    {
        $tenant = Tenant::factory()->create();
        $start = CarbonImmutable::parse('2026-10-01 00:00:00', 'Asia/Tehran')->utc();
        $this->recordCall($tenant, ['started_at' => $start->subSecond()]);
        $this->recordCall($tenant, ['started_at' => $start]);
        $this->recordCall($tenant, ['started_at' => $start->addDay()]);
        $this->actingAs($this->operator($tenant));
        $query = '?range=custom&from=2026-10-01&to=2026-10-01';
        $this->get('/reports'.$query)->assertOk()->assertViewHas('stats', fn ($stats) => (int) $stats->total === 1);
        $this->get('/calls'.$query)->assertOk()->assertViewHas('calls', fn ($calls) => $calls->total() === 1);
    }

    public function test_empty_state_validation_and_permission_gate(): void
    {
        $tenant = Tenant::factory()->create();
        $user = $this->operator($tenant);
        $this->actingAs($user)->get('/reports')->assertOk()->assertSee('تماسی مطابق این فیلترها ثبت نشده است.');
        $this->getJson('/reports?range=custom&from=2026-10-02&to=2026-10-01')->assertUnprocessable();
        $this->getJson('/calls?phone=invalid')->assertUnprocessable();
        $this->getJson('/reports?hour=24')->assertUnprocessable();
        $user->permissions()->delete();
        $this->get('/reports')->assertForbidden();
    }
}
